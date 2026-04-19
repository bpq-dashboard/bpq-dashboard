#!/bin/bash
# restore-radio2.sh — Restore RADIO 2 scan frequencies in bpq32.cfg
# Run this if the VARA HF terminal didn't cleanly restore frequencies on disconnect
#
# Usage: bash restore-radio2.sh

BPQ_CFG="/home/linbpq/bpq32.cfg"  # adjust to your LinBPQ path
BACKUP="/var/www/tprfn/cache/vara-sessions/bpq32-radio-backup.txt"
BPQ_HTTP="http://127.0.0.1:8008"
BPQ_USER="YOURCALL"
BPQ_PASS="YOURPASSWORD"

echo "======================================================"
echo "  RADIO 2 Frequency Restore"
echo "======================================================"

# ── Check backup exists ────────────────────────────────────────────
if [[ ! -f "$BACKUP" ]]; then
    echo "✓ No backup file found — frequencies not modified or already restored"
    grep -A 8 "^RADIO 2" "$BPQ_CFG"
    exit 0
fi

echo "► Backup found: $BACKUP"
echo ""
echo "Backup contents:"
cat "$BACKUP"
echo ""

# ── Restore backup into bpq32.cfg ─────────────────────────────────
echo "► Restoring original RADIO 2 block..."

# Extract current RADIO 2 block and replace with backup
python3 << PYEOF
import re, sys

cfg_path    = "$BPQ_CFG"
backup_path = "$BACKUP"

with open(cfg_path, 'r') as f:
    cfg = f.read()

with open(backup_path, 'r') as f:
    original = f.read()

m = re.search(r'^(RADIO 2 ?\n[\s\S]*?\*{5})', cfg, re.MULTILINE)
if not m:
    print("ERROR: RADIO 2 block not found in bpq32.cfg")
    sys.exit(1)

cfg = cfg.replace(m.group(1), original.rstrip())
with open(cfg_path, 'w') as f:
    f.write(cfg)

print("OK: bpq32.cfg restored")
PYEOF

if [[ $? -ne 0 ]]; then
    echo "✗ Restore failed — check manually"
    exit 1
fi

echo ""
echo "► Current RADIO 2 block:"
grep -A 12 "^RADIO 2" "$BPQ_CFG"
echo ""

# ── Send RIGRECONFIG via BPQ HTTP interface ────────────────────────
echo "► Sending RIGRECONFIG to BPQ..."

# Get terminal session token
TOKEN=$(curl -s -u "${BPQ_USER}:${BPQ_PASS}" \
    "${BPQ_HTTP}/Node/Terminal.html" \
    | grep -o 'TermClose?T[0-9A-Fa-f]*' \
    | grep -o 'T[0-9A-Fa-f]*')

if [[ -z "$TOKEN" ]]; then
    echo "✗ Could not get BPQ terminal token — is LinBPQ running?"
    echo "  Manually restart LinBPQ: sudo systemctl restart linbpq"
    exit 1
fi

echo "  Token: $TOKEN"

# Send PASSWORD to get SYSOP access
curl -s -u "${BPQ_USER}:${BPQ_PASS}" \
    -X POST "${BPQ_HTTP}/TermInput?${TOKEN}" \
    --data "input=PASSWORD" > /dev/null
sleep 0.3

# Send RIGRECONFIG
curl -s -u "${BPQ_USER}:${BPQ_PASS}" \
    -X POST "${BPQ_HTTP}/TermInput?${TOKEN}" \
    --data "input=RIGRECONFIG" > /dev/null
sleep 0.5

# Check response
RESP=$(curl -s -u "${BPQ_USER}:${BPQ_PASS}" \
    "${BPQ_HTTP}/Node/OutputScreen.html?${TOKEN}" \
    | grep -o 'Rigcontrol[^<]*')

if [[ -n "$RESP" ]]; then
    echo "✓ BPQ confirmed: $RESP"
else
    echo "⚠ No confirmation from BPQ — may still have worked"
fi

# ── Remove backup file ─────────────────────────────────────────────
rm -f "$BACKUP"
echo "✓ Backup file removed"

echo ""
echo "======================================================"
echo "  ✓ DONE — RADIO 2 scan schedule restored"
echo "  BPQ will resume scanning all frequencies within 7s"
echo "======================================================"
