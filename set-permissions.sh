#!/bin/bash
# ============================================================================
# BPQ Dashboard + TPRFN Network Monitor — Set Permissions & Install Nginx Config
# Run as root: sudo bash set-permissions.sh
# ============================================================================

DASH_DIR="/var/www/tprfn"
WEB_USER="www-data"
WEB_GROUP="www-data"
NGINX_CONF="/etc/nginx/sites-available/tprfn.conf"
NGINX_ENABLED="/etc/nginx/sites-enabled/tprfn.conf"

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: This script must be run as root (sudo bash set-permissions.sh)"
    exit 1
fi

# Check if directory exists
if [ ! -d "$DASH_DIR" ]; then
    echo "ERROR: Directory $DASH_DIR not found"
    echo "Edit DASH_DIR at the top of this script to match your installation path"
    exit 1
fi

cd "$DASH_DIR" || exit 1
echo "BPQ Dashboard — Permissions & Security Setup"
echo "Directory: $DASH_DIR"
echo "================================================================"

# ── Step 1: Nginx security config ────────────────────────────────────────────
if [ -f "$DASH_DIR/nginx-tprfn.conf" ]; then
    echo ""
    echo "[1/8] Nginx security configuration"
    if [ -f "$NGINX_CONF" ]; then
        # Check if security headers already present (already installed)
        if grep -q "X-Content-Type-Options" "$NGINX_CONF" 2>/dev/null; then
            echo "  ✓ Security config already installed"
        else
            echo "  Backing up current config to ${NGINX_CONF}.bak"
            cp "$NGINX_CONF" "${NGINX_CONF}.bak"
            cp "$DASH_DIR/nginx-tprfn.conf" "$NGINX_CONF"
            echo "  ✓ Security config installed"
            echo "  ✓ Backup saved to ${NGINX_CONF}.bak"
        fi
    else
        cp "$DASH_DIR/nginx-tprfn.conf" "$NGINX_CONF"
        echo "  ✓ Nginx config installed to $NGINX_CONF"
    fi

    # Ensure symlink exists in sites-enabled
    if [ ! -L "$NGINX_ENABLED" ]; then
        ln -sf "$NGINX_CONF" "$NGINX_ENABLED"
        echo "  ✓ Symlink created in sites-enabled"
    fi

    # Test nginx config
    if nginx -t 2>/dev/null; then
        systemctl reload nginx
        echo "  ✓ Nginx reloaded successfully"
    else
        echo "  ⚠ Nginx config test failed — restoring backup"
        if [ -f "${NGINX_CONF}.bak" ]; then
            cp "${NGINX_CONF}.bak" "$NGINX_CONF"
            systemctl reload nginx
            echo "  ✓ Backup restored"
        fi
        echo "  Run 'nginx -t' to see the error"
    fi
else
    echo ""
    echo "[1/8] Nginx config — nginx-tprfn.conf not found in $DASH_DIR, skipping"
fi

# ── Step 2: Base ownership ───────────────────────────────────────────────────
echo ""
echo "[2/8] Setting base ownership to $WEB_USER:$WEB_GROUP..."
chown -R "$WEB_USER:$WEB_GROUP" "$DASH_DIR"

# ── Step 3: Default file permissions (644) ───────────────────────────────────
echo "[3/8] Setting default file permissions (644)..."
find "$DASH_DIR" -type f -exec chmod 644 {} \;

# ── Step 4: Default directory permissions (755) ──────────────────────────────
echo "[4/8] Setting default directory permissions (755)..."
find "$DASH_DIR" -type d -exec chmod 755 {} \;

# ── Step 5: Restrict config files with credentials (640) ─────────────────────
echo "[5/8] Restricting config files (640 — no world read)..."
CONFIG_COUNT=0
for f in config.php bbs-config.php nws-config.php tprfn-config.php api/config.php includes/bootstrap.php; do
    if [ -f "$f" ]; then
        chmod 640 "$f"
        CONFIG_COUNT=$((CONFIG_COUNT + 1))
    fi
done
echo "  ✓ $CONFIG_COUNT config files restricted"

# ── Step 6: Shell/batch/Python scripts (750, owned by root) ─────────────────
echo "[6/8] Setting script permissions (750, owned by root)..."
SCRIPT_COUNT=0
for ext in sh bat ps1 exp py; do
    while IFS= read -r -d '' f; do
        chmod 750 "$f"
        chown root:root "$f"
        SCRIPT_COUNT=$((SCRIPT_COUNT + 1))
    done < <(find "$DASH_DIR" -type f -name "*.$ext" -print0)
done
echo "  ✓ $SCRIPT_COUNT scripts set to root-owned, 750"

# ── Step 7: Writable directories for PHP ─────────────────────────────────────
echo "[7/8] Ensuring writable directories for PHP..."
for d in cache data data/stations logs; do
    if [ -d "$d" ]; then
        chown -R "$WEB_USER:$WEB_GROUP" "$d"
        chmod 755 "$d"
    else
        mkdir -p "$d"
        chown "$WEB_USER:$WEB_GROUP" "$d"
        chmod 755 "$d"
    fi
done
echo "  ✓ cache/, data/, data/stations/, logs/ set to writable"

# ── Step 8: Verify critical protections ──────────────────────────────────────
echo ""
echo "[8/8] Verifying critical file protections..."
PASS=0
FAIL=0

for f in config.php bbs-config.php tprfn-config.php; do
    if [ -f "$f" ]; then
        perms=$(stat -c "%a" "$f" 2>/dev/null)
        if [ "$perms" = "640" ]; then
            echo "  ✓ $f — $perms (protected)"
            PASS=$((PASS + 1))
        else
            echo "  ✗ $f — $perms (should be 640!)"
            FAIL=$((FAIL + 1))
        fi
    fi
done

# Check nginx is blocking config files (if curl available)
if command -v curl &>/dev/null && [ -f "$NGINX_CONF" ]; then
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 "https://tprfn.k1ajd.net/config.php" 2>/dev/null)
    if [ "$HTTP_CODE" = "403" ]; then
        echo "  ✓ Nginx blocks config.php — HTTP $HTTP_CODE"
        PASS=$((PASS + 1))
    elif [ "$HTTP_CODE" = "000" ]; then
        echo "  - Nginx test skipped (could not connect)"
    else
        echo "  ✗ config.php returned HTTP $HTTP_CODE (should be 403!)"
        FAIL=$((FAIL + 1))
    fi
fi

echo ""
echo "================================================================"
echo "Permissions set successfully"
echo "  Files protected: $PASS passed, $FAIL failed"
echo ""
echo "Verify with:"
echo "  ls -la $DASH_DIR"
echo "  ls -la $DASH_DIR/config.php"
echo "  curl -s -o /dev/null -w '%{http_code}' https://tprfn.k1ajd.net/config.php"
echo "  curl -s -o /dev/null -w '%{http_code}' https://tprfn.k1ajd.net/includes/"
