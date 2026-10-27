#!/usr/bin/env python3
"""
reconcile-production.py — compare a running install against a release package.

    sudo python3 reconcile-production.py PACKAGE [WEB_ROOT] [--out DIR] [--no-bundle]

    PACKAGE   the release .zip, or an unpacked package folder
    WEB_ROOT  the live web folder (default /var/www/bpq-dashboard)

Read-only on the live system. Writes only into the report folder
(default /root/reconcile-YYYYMMDD-HHMM, or ./reconcile-… when not root).

WHAT IT ANSWERS
  For every file the installer would copy (everything except install.sh,
  *.log, .git, private/ and docs/SESSION-JOURNAL.md — same as install.sh):
    same         live file is byte-identical to the package
    site copy    live file matches this station's intended variant in
                 private/live-*/ (e.g. a page that keeps a map API key)
    old live     live file still matches a recorded *.live-before copy,
                 i.e. a deploy described in private/ never happened
    DIFFERS      live and package disagree — someone has to decide which
                 is right (report shows which is newer and the diff)
    missing      in the package, not on the live server
  Plus files on the live server that the package doesn't have, sorted into
  code/pages (need a decision), site content (PDFs, images), runtime data
  and credential files (only checked for safe permissions, never read).

  Live-only files matching private/reconcile-ignore.txt (another site in the
  same web folder, generated data files, logs) are counted per reason and
  left out of the review list and the bundle.

  System files: package *.service/*.timer against /etc/systemd/system
  (install path rewritten to WEB_ROOT, as install.sh does), and any pairs
  listed in private/system-files.txt (e.g. the live nginx site config).

OUTPUT (in the report folder)
  report.txt                 everything printed to the screen
  diffs/<path>.diff          package → live unified diff for each DIFFERS file
  production-changes.tgz     the live copies of every DIFFERS file and every
                             live-only code file — the bundle to merge back
                             into the package. Credential files are never
                             included; files that look like they hold a
                             password or key are flagged in the report.

Exit code: 0 = in sync (only expected differences), 1 = drift to review,
2 = could not run.
"""

import argparse
import datetime as dt
import difflib
import fnmatch
import hashlib
import io
import os
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import zipfile

# ── what install.sh copies (keep in step with its rsync --exclude list) ──
INSTALL_EXCLUDES = ["install.sh", "*.log", ".git", ".git/*", "private", "private/*",
                    "docs/SESSION-JOURNAL.md"]

# Live-only files under these top-level folders are runtime data / install output.
RUNTIME_DIRS = {"cache", "logs", "data", "archives", "wavenode-logs", "img",
                "yapp-files", ".well-known"}

# Local credential files (templates ship as *.example). Never read, never bundled.
CREDENTIAL_FILES = {"config.php", "db-config.php", "bbs-config.php", "nws-config.php"}

CONTENT_EXT = {".pdf", ".png", ".jpg", ".jpeg", ".gif", ".svg", ".ico", ".webp",
               ".mp3", ".wav", ".mp4", ".txt", ".csv", ".json", ".xml", ".vara"}
CODE_EXT = {".html", ".htm", ".php", ".js", ".css", ".py", ".sh", ".pl"}
DOC_EXT = {".md", ".pdf", ".example", ".sql", ".service", ".timer", ".conf",
           ".sudoers", ".bat", ".txt"}
DOC_DIRS = {"docs", "LICENSES", "sql", "tools"}

SECRET_RE = re.compile(
    r"""(?ix)
    (pass(word|wd)?|secret|token|api[_-]?key|apikey)\s*['"]?\s*(=>|=|:)\s*['"][^'"\s]{6,}['"]
    | -----BEGIN[ A-Z]*PRIVATE\ KEY-----
    | IDENTIFIED\s+BY\s+'[^']{6,}'
    """)
PLACEHOLDER_RE = re.compile(r"(?i)(your|change|example|placeholder|xxxx|\*\*\*)")

MAX_DIFF_LINES = 4000


# ── helpers ────────────────────────────────────────────────────────────
class Report:
    def __init__(self):
        self.lines = []
        self.color = sys.stdout.isatty()

    def _c(self, code, s):
        return f"\033[{code}m{s}\033[0m" if self.color else s

    def h(self, s):
        self.out(""); self.out(self._c("1", s), plain=s)

    def out(self, s="", plain=None):
        print(s)
        self.lines.append(plain if plain is not None else s)

    def tag(self, tag, msg):
        colors = {"OK": "32", "SAME": "32", "SITE": "36", "INFO": "36", "WARN": "33",
                  "DIFF": "31", "MISS": "31", "OLD": "31", "FAIL": "31", "REVIEW": "33"}
        t = f"{tag:<6}"
        self.out(f"  {self._c(colors.get(tag, '0'), t)}  {msg}", plain=f"  {t}  {msg}")


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 16), b""):
            h.update(chunk)
    return h.hexdigest()


def is_text(path, limit=8192):
    try:
        with open(path, "rb") as f:
            chunk = f.read(limit)
        return b"\0" not in chunk
    except OSError:
        return False


def read_lines(path):
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        return f.readlines()


def excluded(rel, patterns):
    return any(fnmatch.fnmatch(rel, p) for p in patterns)


def fmt_time(ts):
    return dt.datetime.fromtimestamp(ts).strftime("%Y-%m-%d %H:%M")


def walk_files(root, follow_top_symlinks=False):
    """Relative paths of regular files under root. Symlinked dirs are not followed."""
    out = []
    for dirpath, dirnames, filenames in os.walk(root, followlinks=False):
        rel_dir = os.path.relpath(dirpath, root)
        rel_dir = "" if rel_dir == "." else rel_dir
        for name in filenames:
            full = os.path.join(dirpath, name)
            if os.path.islink(full) or not os.path.isfile(full):
                continue
            out.append(os.path.join(rel_dir, name) if rel_dir else name)
    return out


def find_package_root(base):
    """The folder that holds install.sh / CHANGELOG.md (zip may have one top folder)."""
    for marker in ("install.sh", "CHANGELOG.md"):
        if os.path.isfile(os.path.join(base, marker)):
            return base
    entries = [e for e in os.listdir(base) if not e.startswith(".")]
    if len(entries) == 1 and os.path.isdir(os.path.join(base, entries[0])):
        return find_package_root(os.path.join(base, entries[0]))
    return None


def site_terms(pkg_root):
    terms = []
    path = os.path.join(pkg_root, "private", "prepublish-terms.txt")
    if os.path.isfile(path):
        for line in read_lines(path):
            line = line.strip()
            if line and not line.startswith("#"):
                terms.append(line.lstrip("!").lower())
    return terms


def looks_secret(path):
    if not is_text(path):
        return False
    try:
        for line in read_lines(path):
            m = SECRET_RE.search(line)
            if m and not PLACEHOLDER_RE.search(m.group(0)):
                return True
    except OSError:
        pass
    return False


def systemctl(*args):
    try:
        r = subprocess.run(["systemctl", *args], capture_output=True, text=True, timeout=10)
        return r.stdout.strip()
    except (OSError, subprocess.SubprocessError):
        return ""


# ── main ───────────────────────────────────────────────────────────────
def main():
    ap = argparse.ArgumentParser(description="Compare a live install against a release package.")
    ap.add_argument("package", help="release .zip or unpacked package folder")
    ap.add_argument("web_root", nargs="?", default="/var/www/bpq-dashboard",
                    help="live web folder (default /var/www/bpq-dashboard)")
    ap.add_argument("--out", help="report folder (default /root/reconcile-YYYYMMDD-HHMM)")
    ap.add_argument("--no-bundle", action="store_true", help="don't create production-changes.tgz")
    args = ap.parse_args()

    web = os.path.abspath(args.web_root)
    if not os.path.isdir(web):
        print(f"Web folder not found: {web}"); return 2

    tmp = None
    if os.path.isfile(args.package) and zipfile.is_zipfile(args.package):
        tmp = tempfile.mkdtemp(prefix="reconcile-pkg-")
        with zipfile.ZipFile(args.package) as z:
            for info in z.infolist():
                target = os.path.realpath(os.path.join(tmp, info.filename))
                if not target.startswith(os.path.realpath(tmp) + os.sep):
                    print(f"Unsafe path in zip, refusing: {info.filename}"); return 2
                z.extract(info, tmp)
                if not info.is_dir():     # keep the zip's file times (used for "newer")
                    t = dt.datetime(*info.date_time).timestamp()
                    os.utime(target, (t, t))
        pkg_base = tmp
    elif os.path.isdir(args.package):
        pkg_base = args.package
    else:
        print(f"Package not found or not a zip/folder: {args.package}"); return 2

    pkg = find_package_root(pkg_base)
    if not pkg:
        print("Couldn't find the package's top folder (no install.sh or CHANGELOG.md)."); return 2

    stamp = dt.datetime.now().strftime("%Y%m%d-%H%M")
    default_parent = "/root" if os.geteuid() == 0 and os.path.isdir("/root") else os.getcwd()
    out = os.path.abspath(args.out or os.path.join(default_parent, f"reconcile-{stamp}"))
    os.makedirs(os.path.join(out, "diffs"), exist_ok=True)

    R = Report()
    rc = run(R, pkg, web, out, args)

    with open(os.path.join(out, "report.txt"), "w") as f:
        f.write("\n".join(R.lines) + "\n")
    print(f"\nReport folder: {out}")
    if tmp:
        shutil.rmtree(tmp, ignore_errors=True)
    return rc


def run(R, pkg, web, out, args):
    R.out(f"Package : {pkg}")
    R.out(f"Live    : {web}")
    R.out(f"Run     : {dt.datetime.now():%Y-%m-%d %H:%M} on {os.uname().nodename}")
    terms = site_terms(pkg)

    pkg_files = sorted(f for f in walk_files(pkg) if not excluded(f, INSTALL_EXCLUDES))

    # Station ignore list: "<glob>   # reason" — live-only files matching a
    # glob are counted under that reason and not reviewed or bundled.
    ignore_rules = []
    ign = os.path.join(pkg, "private", "reconcile-ignore.txt")
    if os.path.isfile(ign):
        for line in read_lines(ign):
            pat, _, why = line.partition("#")
            pat = pat.strip()
            if pat:
                ignore_rules.append((pat, why.strip() or pat))
    live_files = set(walk_files(web))

    # Station-specific variants recorded in private/live-*/ :
    #   name            -> the intended live version (site copy)
    #   name.live-*     -> an older live version from before a deploy
    site_copies, before_copies = {}, {}
    priv = os.path.join(pkg, "private")
    if os.path.isdir(priv):
        for d in sorted(os.listdir(priv)):
            if not d.startswith("live-") or not os.path.isdir(os.path.join(priv, d)):
                continue
            for rel in walk_files(os.path.join(priv, d)):
                full = os.path.join(priv, d, rel)
                m = re.match(r"^(.*)\.live-[^/]+$", rel)
                if m:
                    before_copies.setdefault(m.group(1), []).append((f"private/{d}/{rel}", sha256(full)))
                else:
                    site_copies.setdefault(rel, []).append((f"private/{d}/{rel}", sha256(full)))

    counts = {k: 0 for k in ("same", "site", "old", "differs", "missing_code", "missing_other")}
    differs, bundle = [], []

    # ── 1. package files vs live ─────────────────────────────────────
    R.h("1. Package files on the live server")
    for rel in pkg_files:
        p = os.path.join(pkg, rel)
        l = os.path.join(web, rel)
        if rel not in live_files:
            ext = os.path.splitext(rel)[1].lower()
            top = rel.split("/", 1)[0]
            if ext in CODE_EXT and top not in DOC_DIRS and rel not in ("fix-permissions.sh", "set-permissions.sh"):
                counts["missing_code"] += 1
                R.tag("MISS", f"{rel} — in the package, not on the live server")
            else:
                counts["missing_other"] += 1
            continue
        ph, lh = sha256(p), sha256(l)
        if ph == lh:
            counts["same"] += 1
            continue
        hit = next((src for src, h in site_copies.get(rel, []) if h == lh), None)
        if hit:
            counts["site"] += 1
            R.tag("SITE", f"{rel} — matches this station's copy {hit} (expected)")
            continue
        hit = next((src for src, h in before_copies.get(rel, []) if h == lh), None)
        if hit:
            counts["old"] += 1
            R.tag("OLD", f"{rel} — live file is still the pre-deploy version {hit}")
            continue

        counts["differs"] += 1
        pm, lm = os.path.getmtime(p), os.path.getmtime(l)
        newer = "live is newer" if lm > pm + 60 else "package is newer" if pm > lm + 60 else "same age"
        detail = f"{newer} (package {fmt_time(pm)}, live {fmt_time(lm)})"
        if is_text(p) and is_text(l):
            a, b = read_lines(p), read_lines(l)
            diff = list(difflib.unified_diff(a, b, f"package/{rel}", f"live/{rel}", n=3))
            added = sum(1 for d in diff if d.startswith("+") and not d.startswith("+++"))
            removed = sum(1 for d in diff if d.startswith("-") and not d.startswith("---"))
            site_lines = sum(1 for d in diff if d[:1] in "+-" and d[:3] not in ("+++", "---")
                             and any(t in d.lower() for t in terms)) if terms else 0
            dpath = os.path.join(out, "diffs", rel + ".diff")
            os.makedirs(os.path.dirname(dpath), exist_ok=True)
            with open(dpath, "w") as f:
                f.writelines(diff[:MAX_DIFF_LINES])
                if len(diff) > MAX_DIFF_LINES:
                    f.write(f"\n... diff truncated ({len(diff)} lines)\n")
            detail += f"; +{added} / -{removed} lines"
            if site_lines:
                detail += f"; {site_lines} changed line(s) mention this station"
        R.tag("DIFF", f"{rel} — {detail}")
        differs.append(rel)
        bundle.append(rel)

    if not (counts["site"] or counts["old"] or counts["differs"] or counts["missing_code"]):
        R.tag("OK", f"All {counts['same']} package files on the live server are identical")
    if counts["missing_other"]:
        R.tag("INFO", f"{counts['missing_other']} docs/templates/system files from the package "
                      f"aren't in the web folder (normal: README, *.md, *.service, sql/, …)")

    # ── 2. live-only files ───────────────────────────────────────────
    R.h("2. Live files the package doesn't have")
    pkg_set = set(pkg_files)
    live_only = sorted(f for f in live_files if f not in pkg_set)
    groups = {"code": [], "content": [], "runtime": [], "cred": [], "other": []}
    ignored = {}
    for rel in live_only:
        rule = next((why for pat, why in ignore_rules if fnmatch.fnmatch(rel, pat)), None)
        if rule:
            ignored[rule] = ignored.get(rule, 0) + 1
            continue
        top = rel.split("/", 1)[0]
        base = os.path.basename(rel)
        ext = os.path.splitext(rel)[1].lower()
        if "/" not in rel and base in CREDENTIAL_FILES:
            groups["cred"].append(rel)
        elif top in RUNTIME_DIRS and "/" in rel:
            groups["runtime"].append(rel)
        elif ext in CODE_EXT:
            groups["code"].append(rel)
        elif ext in CONTENT_EXT:
            groups["content"].append(rel)
        else:
            groups["other"].append(rel)

    review_code = []
    for rel in groups["code"]:
        sc = site_copies.get(rel)
        if sc and sha256(os.path.join(web, rel)) in {h for _, h in sc}:
            R.tag("SITE", f"{rel} — live-only, matches {sc[0][0]} (expected)")
            continue
        R.tag("REVIEW", f"{rel} — code/page on the live server only "
                        f"({fmt_time(os.path.getmtime(os.path.join(web, rel)))}): add to package, or retire")
        review_code.append(rel)
        bundle.append(rel)
    if not groups["code"]:
        R.tag("OK", "No live-only code or pages")

    for rel in groups["cred"]:
        st = os.stat(os.path.join(web, rel))
        mode = stat.S_IMODE(st.st_mode)
        tmpl = os.path.join(pkg, rel + ".example")
        note = "" if os.path.isfile(tmpl) else " (no .example template in package)"
        if mode & 0o004:
            R.tag("WARN", f"{rel} — credential file is world-readable ({oct(mode)[2:]}); "
                          f"should be 640{note}")
        else:
            R.tag("OK", f"{rel} — credential file, permissions {oct(mode)[2:]}{note}")

    if groups["content"]:
        exts = {}
        for rel in groups["content"]:
            exts[os.path.splitext(rel)[1].lower() or "(none)"] = exts.get(os.path.splitext(rel)[1].lower() or "(none)", 0) + 1
        summary = ", ".join(f"{n} {e}" for e, n in sorted(exts.items(), key=lambda x: -x[1]))
        R.tag("INFO", f"{len(groups['content'])} site content files ({summary}) — station data, not package material")
    if groups["runtime"]:
        R.tag("INFO", f"{len(groups['runtime'])} runtime files under "
                      f"{', '.join(sorted({r.split('/', 1)[0] + '/' for r in groups['runtime']}))} — ignored")
    for why, n in ignored.items():
        R.tag("INFO", f"{n} file(s) ignored: {why}")
    for rel in groups["other"]:
        R.tag("REVIEW", f"{rel} — live-only file of unknown kind")

    # ── 3. system files ──────────────────────────────────────────────
    R.h("3. System files (systemd, nginx, …)")
    units = sorted(f for f in pkg_files if "/" not in f and f.endswith((".service", ".timer")))
    for unit in units:
        installed = os.path.join("/etc/systemd/system", unit)
        if not os.path.isfile(installed):
            R.tag("INFO", f"{unit} — not installed")
            continue
        want = open(os.path.join(pkg, unit), encoding="utf-8", errors="replace").read() \
            .replace("/var/www/bpq-dashboard", web)
        have = open(installed, encoding="utf-8", errors="replace").read()
        state = systemctl("is-active", unit) or "status unknown"
        if want == have:
            R.tag("SAME", f"{unit} — matches package ({state})")
        else:
            d = list(difflib.unified_diff(want.splitlines(True), have.splitlines(True),
                                          f"package/{unit}", installed, n=2))
            with open(os.path.join(out, "diffs", unit + ".diff"), "w") as f:
                f.writelines(d)
            R.tag("DIFF", f"{unit} — installed unit differs from package ({state}); see diffs/{unit}.diff")
            counts["differs"] += 1
            bundle.append(("sys", installed))

    pairs_file = os.path.join(pkg, "private", "system-files.txt")
    if os.path.isfile(pairs_file):
        for line in read_lines(pairs_file):
            line = line.split("#", 1)[0].strip()
            if not line:
                continue
            try:
                src, dst = line.split()
            except ValueError:
                R.tag("WARN", f"private/system-files.txt: can't read line '{line}'"); continue
            sp = os.path.join(pkg, src)
            if not os.path.isfile(sp):
                R.tag("WARN", f"{src} — listed in system-files.txt but not in package"); continue
            if not os.path.exists(dst):
                R.tag("MISS", f"{dst} — not on this server (package copy: {src})")
                counts["differs"] += 1; continue
            real = os.path.realpath(dst)
            link = f" (symlink → {real})" if real != dst else ""
            if sha256(sp) == sha256(real):
                R.tag("SAME", f"{dst}{link} — matches {src}")
            else:
                d = list(difflib.unified_diff(read_lines(sp), read_lines(real), src, dst, n=3))
                name = os.path.basename(dst) + ".diff"
                with open(os.path.join(out, "diffs", name), "w") as f:
                    f.writelines(d)
                R.tag("DIFF", f"{dst}{link} — differs from {src}; see diffs/{name}")
                counts["differs"] += 1
                bundle.append(("sys", dst))
    else:
        R.tag("INFO", "No private/system-files.txt — nginx/other system files not compared")

    sudoers = os.path.join(pkg, "www-data-tools.sudoers")
    if os.path.isfile(sudoers) and os.path.isdir("/etc/sudoers.d"):
        want = sha256(sudoers)
        hits = [f for f in os.listdir("/etc/sudoers.d")
                if os.path.isfile(os.path.join("/etc/sudoers.d", f))
                and sha256(os.path.join("/etc/sudoers.d", f)) == want]
        R.tag("SAME" if hits else "INFO",
              f"www-data-tools.sudoers — {'installed as /etc/sudoers.d/' + hits[0] if hits else 'no identical copy in /etc/sudoers.d (may be customised or not installed)'}")

    # ── 4. bundle ────────────────────────────────────────────────────
    R.h("4. Bundle for merging back into the package")
    flagged = []
    if not bundle:
        R.tag("OK", "Nothing to bundle — live matches the package apart from expected differences")
    elif args.no_bundle:
        R.tag("INFO", f"{len(bundle)} file(s) would be bundled (skipped: --no-bundle)")
    else:
        tgz = os.path.join(out, "production-changes.tgz")
        manifest = io.StringIO()
        manifest.write(f"Live copies from {web} on {os.uname().nodename}, {dt.datetime.now():%Y-%m-%d %H:%M}\n")
        manifest.write("web/<path> = live web file, system/<path> = live system file\n\n")
        with tarfile.open(tgz, "w:gz") as tar:
            for item in bundle:
                if isinstance(item, tuple):
                    src = os.path.realpath(item[1]); arc = "system" + item[1]
                else:
                    src = os.path.join(web, item); arc = "web/" + item
                if os.path.basename(src) in CREDENTIAL_FILES:
                    continue
                tar.add(src, arcname=arc)
                secret = looks_secret(src)
                if secret:
                    flagged.append(arc)
                manifest.write(f"{arc}{'   <-- looks like it holds a password/key' if secret else ''}\n")
            data = manifest.getvalue().encode()
            ti = tarfile.TarInfo("MANIFEST.txt"); ti.size = len(data); ti.mtime = int(dt.datetime.now().timestamp())
            tar.addfile(ti, io.BytesIO(data))
        os.chmod(tgz, 0o600)
        R.tag("OK", f"production-changes.tgz — {len(bundle)} file(s) + MANIFEST.txt")
        for arc in flagged:
            R.tag("WARN", f"{arc} looks like it holds a password or key — keep the bundle private")

    # ── summary ──────────────────────────────────────────────────────
    R.h("Summary")
    R.out(f"  {counts['same']} identical, {counts['site']} expected station copies, "
          f"{counts['differs']} differ, {counts['old']} still pre-deploy, "
          f"{counts['missing_code']} code files missing from live, "
          f"{len(review_code)} live-only code files to review")
    drift = counts["differs"] + counts["old"] + counts["missing_code"] + len(review_code) + len(groups["other"])
    if drift == 0:
        R.out("  Live server and package are in sync.")
        return 0
    R.out("  Review the DIFF / OLD / MISS / REVIEW lines. Diffs are in diffs/; live copies in production-changes.tgz.")
    return 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(2)
