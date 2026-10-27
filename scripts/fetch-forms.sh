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
# ─────────────────────────────────────────────────────────────────────────
set -u

FORMS_REPO="${FORMS_REPO:-https://github.com/N3MEL39/TPRFN_HTML_Forms}"
FORMS_REPO="${FORMS_REPO%/}"; FORMS_REPO="${FORMS_REPO%.git}"

WEB_ROOT=""; FORCE=0
for a in "$@"; do
    case "$a" in
        --force) FORCE=1 ;;
        -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
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

if [[ -e "$TARGET" ]]; then
    PREV="/tmp/pktnet-forms.prev-$(date +%Y%m%d-%H%M%S)"
    mv "$TARGET" "$PREV" && echo "Previous forms moved to $PREV"
fi
mkdir -p "$TARGET"
cp -a "$SRC/." "$TARGET/"
rm -rf "$TARGET/.git" "$TARGET/.github" "$TARGET/.gitea" "$TARGET/.forgejo"
# Folder names must match the case the index links use (2026-10-19): the
# suite ships General/ ICS/ Weather/ but index.html links general/ ics/
# weather/, which works on Windows and macOS but is a 404 on Linux. Rename
# any folder the index links to under a different case; nothing to do once
# the two agree.
for linked in $(grep -oE 'href="[^":/?#]+/' "$TARGET/index.html" | sed 's/^href="//; s#/$##' | sort -u); do
    [[ -d "$TARGET/$linked" ]] && continue
    actual="$(find "$TARGET" -mindepth 1 -maxdepth 1 -type d -iname "$linked" -printf '%f\n' | head -1)"
    if [[ -n "$actual" ]]; then
        mv "$TARGET/$actual" "$TARGET/$linked" && echo "Renamed folder $actual/ to $linked/ to match the index links"
    fi
done

printf 'source=%s\nmethod=%s\nfetched=%s\n' "$FORMS_REPO" "$got" "$(date -u +%FT%TZ)" > "$TARGET/.source"

# Same owner as the web root; plain static-file permissions.
owner="$(stat -c '%U:%G' "$WEB_ROOT")"
chown -R "$owner" "$TARGET" 2>/dev/null || true
find "$TARGET" -type d -exec chmod 755 {} +
find "$TARGET" -type f -exec chmod 644 {} +

echo "Forms suite installed in $TARGET ($(find "$TARGET" -name '*.html' | wc -l) pages, via $got)."
exit 0
