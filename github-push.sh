#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
#  BPQ Dashboard — GitHub Release Push Script
#  Run from ARSSYSTEM after testing is complete
#
#  What this does:
#    1. Verifies the deploy archive exists and looks sane
#    2. Checks git status and shows what will be pushed
#    3. Commits changed files with a release message
#    4. Tags the release (e.g. v1.5.6)
#    5. Pushes to GitHub
#    6. Optionally creates a GitHub Release with the zip attached
#
#  Prerequisites:
#    git installed:     sudo apt install git
#    gh installed:      sudo apt install gh  (for release creation)
#    gh authenticated:  gh auth login
#    Remote configured: git remote -v
#
#  Usage:
#    bash github-push.sh                    # interactive
#    bash github-push.sh --version v1.5.7   # specify version
#    bash github-push.sh --dry-run          # preview only
# ═══════════════════════════════════════════════════════════════════

set -euo pipefail

# ── Colour helpers ─────────────────────────────────────────────────
RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[0;33m'
BLU='\033[0;34m'; CYN='\033[0;36m'; WHT='\033[1;37m'
BOLD='\033[1m';   NC='\033[0m'

say()  { echo -e "$(date '+%H:%M:%S') ${WHT}[....] $*${NC}"; }
ok()   { echo -e "$(date '+%H:%M:%S') ${GRN}[ OK ] $*${NC}"; }
warn() { echo -e "$(date '+%H:%M:%S') ${YLW}[WARN] $*${NC}"; }
err()  { echo -e "$(date '+%H:%M:%S') ${RED}[FAIL] $*${NC}"; }
hdr()  { echo -e "\n${BOLD}${CYN}╔═══ $* ═══╗${NC}"; }
ask()  { echo -e "\n${BOLD}${YLW}[INPUT] $*${NC}"; }
die()  { echo -e "\n${RED}${BOLD}FATAL: $*${NC}"; exit 1; }

# ── Parse arguments ────────────────────────────────────────────────
VERSION=""
DRY_RUN=0
SKIP_RELEASE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version) VERSION="$2"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        --skip-release) SKIP_RELEASE=1; shift ;;
        --help)
            echo "Usage: bash github-push.sh [--version v1.5.x] [--dry-run] [--skip-release]"
            exit 0 ;;
        *) die "Unknown argument: $1" ;;
    esac
done

# ── Configuration — edit these for your setup ──────────────────────
REPO_DIR="${REPO_DIR:-$(pwd)}"          # directory containing your git repo
DEPLOY_ZIP_PATTERN="bpq-dashboard-v*.*.*.zip"
GITHUB_REPO="bpq-dashboard/bpq-dashboard"  # owner/repo on GitHub

# ── Sanity checks ─────────────────────────────────────────────────
clear
echo -e "${BOLD}${CYN}"
cat << 'BANNER'
  ╔══════════════════════════════════════════════════════╗
  ║      BPQ Dashboard — GitHub Release Push            ║
  ╚══════════════════════════════════════════════════════╝
BANNER
echo -e "${NC}"

# Must have git
command -v git &>/dev/null || die "git not installed. Run: sudo apt install git"

# Must be in a git repo
git -C "$REPO_DIR" rev-parse --git-dir &>/dev/null || \
    die "Not a git repository: $REPO_DIR\nRun: git init && git remote add origin YOUR_GITHUB_URL"

# Check remote
REMOTE_URL=$(git -C "$REPO_DIR" remote get-url origin 2>/dev/null || echo "")
[[ -z "$REMOTE_URL" ]] && die "No git remote 'origin' configured.\nRun: git remote add origin https://github.com/${GITHUB_REPO}.git"
ok "Git remote: $REMOTE_URL"

# ── Find deploy zip ────────────────────────────────────────────────
hdr "STEP 1 — LOCATE DEPLOY ARCHIVE"

# Look in current dir and parent
DEPLOY_ZIP=""
for search_dir in "." ".." "$HOME"; do
    found=$(find "$search_dir" -maxdepth 1 -name "$DEPLOY_ZIP_PATTERN" 2>/dev/null | sort -V | tail -1)
    if [[ -n "$found" ]]; then
        DEPLOY_ZIP="$found"
        break
    fi
done

if [[ -z "$DEPLOY_ZIP" ]]; then
    ask "Deploy zip not found automatically. Enter path to bpq-dashboard-vX.X.X-deploy.zip:"
    read -r DEPLOY_ZIP
    [[ -f "$DEPLOY_ZIP" ]] || die "File not found: $DEPLOY_ZIP"
fi

ok "Deploy archive: $DEPLOY_ZIP"

# Extract version from zip filename if not provided
if [[ -z "$VERSION" ]]; then
    VERSION=$(basename "$DEPLOY_ZIP" | grep -oP 'v\d+\.\d+\.\d+' | head -1)
fi

if [[ -z "$VERSION" ]]; then
    ask "Enter version tag (e.g. v1.5.6):"
    read -r VERSION
fi

[[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || \
    die "Version must be in format v1.2.3, got: $VERSION"

ok "Version: $VERSION"

# Verify zip contains expected files
EXPECTED=("install.sh" "README.md" "bpq-rf-connections.html" "bpq-telnet.html" "bpq-vara.html" "scripts/bpq-vara-daemon.py")
say "Verifying deploy archive contents..."
ZIP_LIST=$(unzip -l "$DEPLOY_ZIP" 2>/dev/null)
for f in "${EXPECTED[@]}"; do
    if echo "$ZIP_LIST" | grep -q "$f"; then
        ok "  ✓ $f"
    else
        warn "  ✗ $f — not found in archive"
    fi
done

# ── Show git status ────────────────────────────────────────────────
hdr "STEP 2 — GIT STATUS"

cd "$REPO_DIR"

say "Current branch:"
git branch --show-current

say "Uncommitted changes:"
git status --short

say "Recent commits:"
git log --oneline -5

# ── Copy deploy zip to repo ────────────────────────────────────────
hdr "STEP 3 — COPY ARCHIVE TO REPO"

DEST_ZIP="$REPO_DIR/releases/${VERSION}-deploy.zip"
mkdir -p "$REPO_DIR/releases"

if [[ $DRY_RUN -eq 1 ]]; then
    say "[DRY RUN] Would copy: $DEPLOY_ZIP → $DEST_ZIP"
else
    cp "$DEPLOY_ZIP" "$DEST_ZIP"
    ok "Copied to: $DEST_ZIP"
fi

# ── Commit ────────────────────────────────────────────────────────
hdr "STEP 4 — COMMIT"

# Build commit message from changed files
CHANGED=$(git status --short | head -20)

COMMIT_MSG="Release ${VERSION}

New in this release:
- BPQ Telnet Client (bpq-telnet.html) with live NetROM sidebar
- VARA HF Terminal (bpq-vara.html) with sysop auth and frequency QSY
- NetROM nodes API (bpq-nodes-api.php) — live route table
- All nav bars updated to include Telnet and VARA HF pages
- install.sh: auto-installs bpq-telnet and bpq-vara daemons,
  WebSocket proxy blocks, vara_allowed_stations DB table,
  flrig configuration support
- Comprehensive user manual (README.md)
- Version badges updated to ${VERSION} across all pages"

echo ""
echo -e "${BOLD}Commit message:${NC}"
echo "$COMMIT_MSG"
echo ""
echo -e "${BOLD}Files to commit:${NC}"
git status --short

echo ""
if [[ $DRY_RUN -eq 1 ]]; then
    say "[DRY RUN] Would stage all changes and commit"
else
    ask "Proceed with commit? [Y/n]:"
    read -r CONFIRM
    CONFIRM="${CONFIRM:-Y}"
    if [[ ! "$CONFIRM" =~ ^[Yy] ]]; then
        say "Commit cancelled — exiting"
        exit 0
    fi

    git add -A
    git commit -m "$COMMIT_MSG"
    ok "Committed"
fi

# ── Tag ───────────────────────────────────────────────────────────
hdr "STEP 5 — TAG"

if git tag | grep -q "^${VERSION}$"; then
    warn "Tag $VERSION already exists"
    ask "Delete existing tag and re-create? [y/N]:"
    read -r RETAG
    if [[ "$RETAG" =~ ^[Yy] ]]; then
        if [[ $DRY_RUN -eq 0 ]]; then
            git tag -d "$VERSION"
            git push origin ":refs/tags/$VERSION" 2>/dev/null || true
        fi
        ok "Old tag deleted"
    else
        say "Keeping existing tag"
    fi
fi

if [[ $DRY_RUN -eq 1 ]]; then
    say "[DRY RUN] Would create tag: $VERSION"
else
    git tag -a "$VERSION" -m "BPQ Dashboard ${VERSION}

New in this release:
- BPQ Telnet Client with live NetROM sidebar
- VARA HF Terminal with sysop auth and frequency QSY
- NetROM nodes API
- install.sh auto-installs all new daemons and WebSocket proxies
- Comprehensive user manual
- All nav bars updated, version badges bumped to ${VERSION}"
    ok "Tag created: $VERSION"
fi

# ── Push ──────────────────────────────────────────────────────────
hdr "STEP 6 — PUSH TO GITHUB"

echo -e "  Remote : ${BLU}$REMOTE_URL${NC}"
echo -e "  Branch : ${BLU}$(git branch --show-current)${NC}"
echo -e "  Tag    : ${BLU}$VERSION${NC}"
echo ""

if [[ $DRY_RUN -eq 1 ]]; then
    say "[DRY RUN] Would push commits and tags to origin"
else
    ask "Push to GitHub now? [Y/n]:"
    read -r DOPUSH
    DOPUSH="${DOPUSH:-Y}"
    if [[ ! "$DOPUSH" =~ ^[Yy] ]]; then
        say "Push skipped — run manually: git push origin main --tags"
        exit 0
    fi

    git push origin "$(git branch --show-current)"
    git push origin "$VERSION"
    ok "Pushed to GitHub"
fi

# ── GitHub Release ────────────────────────────────────────────────
hdr "STEP 7 — GITHUB RELEASE"

if [[ $SKIP_RELEASE -eq 1 ]]; then
    say "Skipping GitHub Release creation (--skip-release)"
else
    if ! command -v gh &>/dev/null; then
        warn "GitHub CLI (gh) not installed — skipping release creation"
        warn "Install with: sudo apt install gh && gh auth login"
        warn "Then create release manually at: https://github.com/${GITHUB_REPO}/releases/new"
    else
        RELEASE_NOTES="## BPQ Dashboard ${VERSION}

### New Features
- **BPQ Telnet Client** (\`bpq-telnet.html\`) — browser terminal with live NetROM sidebar, BPQ command reference, auto-refresh every 30s
- **VARA HF Terminal** (\`bpq-vara.html\`) — sysop-authenticated HF terminal via BPQ ATT command, flrig QSY, ITU Region 2 band validation, allowlist management
- **NetROM Nodes API** (\`bpq-nodes-api.php\`) — fetches live NODES and ROUTES from BPQ via telnet

### Installer Improvements
- \`install.sh\` now asks for VARA HF and flrig configuration
- Auto-creates \`vara_allowed_stations\` database table
- Installs \`bpq-telnet\` and \`bpq-vara\` systemd services
- Adds WebSocket proxy blocks (/ws/telnet, /ws/vara) to nginx config
- Patches all new scripts with your callsign and password

### UI Updates
- All dashboard pages updated to include Telnet and VARA HF in the navigation bar
- Version badges updated to ${VERSION}
- Comprehensive user manual (README.md) — covers all 13 dashboard pages

### Files
See \`install.sh\` — the guided installer handles everything automatically.

**Full installation guide:** [README.md](README.md)
**Changelog:** [CHANGELOG.md](CHANGELOG.md)"

        if [[ $DRY_RUN -eq 1 ]]; then
            say "[DRY RUN] Would create GitHub Release $VERSION with deploy zip attached"
        else
            ask "Create GitHub Release with zip attached? [Y/n]:"
            read -r DORELEASE
            DORELEASE="${DORELEASE:-Y}"
            if [[ "$DORELEASE" =~ ^[Yy] ]]; then
                gh release create "$VERSION" \
                    --repo "$GITHUB_REPO" \
                    --title "BPQ Dashboard ${VERSION}" \
                    --notes "$RELEASE_NOTES" \
                    "$DEPLOY_ZIP"
                ok "GitHub Release created: https://github.com/${GITHUB_REPO}/releases/tag/${VERSION}"
            else
                say "Release skipped"
            fi
        fi
    fi
fi

# ── Summary ───────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${CYN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYN}║                   PUSH COMPLETE                     ║${NC}"
echo -e "${BOLD}${CYN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  Version  : ${GRN}${VERSION}${NC}"
echo -e "  Repo     : ${BLU}https://github.com/${GITHUB_REPO}${NC}"
echo -e "  Releases : ${BLU}https://github.com/${GITHUB_REPO}/releases${NC}"
echo ""
if [[ $DRY_RUN -eq 1 ]]; then
    echo -e "  ${YLW}DRY RUN — no changes were made${NC}"
fi
echo ""
