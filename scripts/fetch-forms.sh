#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────
# fetch-forms.sh — download the TPRFN HTML Forms into <web root>/pktnet-forms
#
# The Email Client's "PKTNET Forms" button opens pktnet-forms/index.html.
# The forms are maintained by their authors (N3MEL and contributors) in
# their own repository, so they are downloaded here instead of being
# bundled with the dashboard.
#
# Usage:   sudo bash scripts/fetch-forms.sh [WEB_ROOT] [--force]
#          WEB_ROOT defaults to the folder above this script.
#          --force replaces an existing pktnet-forms folder (the old one is
#          moved to /tmp, never left in the web root).
# Source:  FORMS_REPO=<repo URL> to use a different copy of the suite.
#          FORMS_REF=<branch> to try a specific branch first.
# Keep:    FORMS_PREV_DIR=<folder> keeps the replaced copy there as
#          <folder>/pktnet-forms.prev (one copy; the Maintenance page's
#          "Undo" puts it back). Default: /tmp/pktnet-forms.prev-<date>.
# v1.5.19: the new copy is built next to the old one and swapped in with a
# rename, and pktnet-forms/.source records the author's commit (GitHub), so
# the Maintenance page can show installed vs latest.
# ─────────────────────────────────────────────────────────────────────────
set -u

FORMS_REPO="${FORMS_REPO:-https://github.com/N3MEL39/TPRFN_HTML_Forms}"
FORMS_REPO="${FORMS_REPO%/}"; FORMS_REPO="${FORMS_REPO%.git}"

WEB_ROOT=""; FORCE=0
for a in "$@"; do
    case "$a" in
        --force) FORCE=1 ;;
        -h|--help) sed -n '2,23p' "$0"; exit 0 ;;
        *) WEB_ROOT="$a" ;;
    esac
done
[[ -n "$WEB_ROOT" ]] || WEB_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="$WEB_ROOT/pktnet-forms"

if [[ -f "$TARGET/index.html" && $FORCE -eq 0 ]]; then
    echo "Forms already present in $TARGET (use --force to replace)."
    exit 0
fi

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
got=""

# The author's latest commit (GitHub repositories), recorded in .source
COMMIT=""; COMMIT_DATE=""
if [[ "$FORMS_REPO" =~ ^https://github\.com/([^/]+)/([^/]+)$ ]] && command -v curl >/dev/null; then
    API_JSON="$(curl -fsSL --max-time 20 -H 'Accept: application/vnd.github+json' \
        "https://api.github.com/repos/${BASH_REMATCH[1]}/${BASH_REMATCH[2]}/commits?per_page=1${FORMS_REF:+&sha=$FORMS_REF}" 2>/dev/null)"
    if [[ -n "$API_JSON" ]] && command -v python3 >/dev/null; then
        read -r COMMIT COMMIT_DATE < <(printf '%s' "$API_JSON" | python3 -c 'import json,sys
try:
    c=json.load(sys.stdin)[0]; print(c["sha"][:12], c["commit"]["committer"]["date"])
except Exception: print("", "")' 2>/dev/null)
    fi
fi

# No API answer: ask git for the newest commit instead (v1.5.19)
if [[ -z "$COMMIT" ]] && command -v git >/dev/null; then
    COMMIT="$(GIT_TERMINAL_PROMPT=0 timeout 25 git ls-remote "$FORMS_REPO.git" HEAD 2>/dev/null | awk '{print substr($1,1,12); exit}')"
fi

# 1) Archive download: Gitea/Forgejo (/archive/<ref>.tar.gz) and GitHub
#    (/archive/refs/heads/<ref>.tar.gz) layouts, main then master.
if command -v curl >/dev/null && command -v tar >/dev/null; then
    for ref in ${FORMS_REF:-} main master; do
        for path in "archive/$ref.tar.gz" "archive/refs/heads/$ref.tar.gz"; do
            if curl -fsSL --max-time 60 -o "$TMP/forms.tar.gz" "$FORMS_REPO/$path" 2>/dev/null \
               && mkdir -p "$TMP/x" && tar -xzf "$TMP/forms.tar.gz" -C "$TMP/x" 2>/dev/null; then
                got="archive $path"; break 2
            fi
            rm -rf "$TMP/x" "$TMP/forms.tar.gz"
        done
    done
fi

# 2) git clone, if git is installed
if [[ -z "$got" ]] && command -v git >/dev/null; then
    if GIT_TERMINAL_PROMPT=0 git clone -q --depth 1 "$FORMS_REPO.git" "$TMP/x" 2>/dev/null; then
        if [[ -z "$COMMIT" ]]; then   # commit from the clone itself (v1.5.19)
            COMMIT="$(git -C "$TMP/x" rev-parse --short=12 HEAD 2>/dev/null)"
            COMMIT_DATE="$(git -C "$TMP/x" log -1 --format=%cI 2>/dev/null)"
        fi
        rm -rf "$TMP/x/.git"; got="git clone"
    fi
fi

if [[ -z "$got" ]]; then
    echo "ERROR: could not download the forms suite from $FORMS_REPO" >&2
    echo "       (no network, the repository moved, or it is not public)." >&2
    echo "       The rest of the dashboard works; only the Email Client's" >&2
    echo "       'PKTNET Forms' button will show a 404 until this succeeds." >&2
    exit 1
fi

# The suite's hub page is index.html; use the shallowest one found.
INDEX="$(find "$TMP/x" -maxdepth 3 -type f -name index.html -printf '%d %p\n' | sort -n | head -1 | cut -d' ' -f2-)"
if [[ -z "$INDEX" ]]; then
    echo "ERROR: downloaded $FORMS_REPO ($got) but found no index.html in it." >&2
    exit 1
fi
SRC="$(dirname "$INDEX")"

# Build the new copy next to the old one, then swap with two renames
NEW="$WEB_ROOT/.pktnet-forms.new-$$"
rm -rf "$NEW"; mkdir -p "$NEW" || { echo "ERROR: can't write in $WEB_ROOT" >&2; exit 1; }
cp -a "$SRC/." "$NEW/"
rm -rf "$NEW/.git" "$NEW/.github" "$NEW/.gitea" "$NEW/.forgejo"
# Folder names must match the case the index links use (2026-10-19): the
# suite ships General/ ICS/ Weather/ but index.html links general/ ics/
# weather/, which works on Windows and macOS but is a 404 on Linux. Rename
# any folder the index links to under a different case; nothing to do once
# the two agree.
for linked in $(grep -oE 'href="[^":/?#]+/' "$NEW/index.html" | sed 's/^href="//; s#/$##' | sort -u); do
    [[ -d "$NEW/$linked" ]] && continue
    actual="$(find "$NEW" -mindepth 1 -maxdepth 1 -type d -iname "$linked" -printf '%f\n' | head -1)"
    if [[ -n "$actual" ]]; then
        mv "$NEW/$actual" "$NEW/$linked" && echo "Renamed folder $actual/ to $linked/ to match the index links"
    fi
done
printf 'source=%s\nmethod=%s\nfetched=%s\ncommit=%s\ncommit_date=%s\n' "$FORMS_REPO" "$got" "$(date -u +%FT%TZ)" "$COMMIT" "$COMMIT_DATE" > "$NEW/.source"

if [[ -e "$TARGET" ]]; then
    if [[ -n "${FORMS_PREV_DIR:-}" ]]; then
        PREV="${FORMS_PREV_DIR%/}/pktnet-forms.prev"
        rm -rf "$PREV"
    else
        PREV="/tmp/pktnet-forms.prev-$(date +%Y%m%d-%H%M%S)"
    fi
    if mv "$TARGET" "$PREV"; then
        echo "Previous forms moved to $PREV"
    else
        rm -rf "$NEW"; echo "ERROR: could not move the old forms out of the way" >&2; exit 1
    fi
fi
mv "$NEW" "$TARGET" || { echo "ERROR: could not put the new forms in place" >&2; [[ -n "${PREV:-}" ]] && mv "$PREV" "$TARGET"; exit 1; }

# Same owner as the web root; plain static-file permissions.
owner="$(stat -c '%U:%G' "$WEB_ROOT")"
chown -R "$owner" "$TARGET" 2>/dev/null || true
find "$TARGET" -type d -exec chmod 755 {} +
find "$TARGET" -type f -exec chmod 644 {} +

echo "Forms suite installed in $TARGET ($(find "$TARGET" -name '*.html' | wc -l) pages, via $got)."
exit 0
