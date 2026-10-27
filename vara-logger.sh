#!/bin/bash
# vara-logger.sh — VARA HF log collector for BPQ Dashboard (vara-logger.service)
#
# VARA HF (registered version: VARA Settings > VARA Setup > Syslog) sends its
# session log to a syslog server. On this machine rsyslog receives it (UDP
# 514) and writes it to /var/log/syslog, tagged VARAHF. This script copies
# every new VARAHF line into one file that survives log rotation — the file
# the RF Connections parser reads (config.php 'logs' => 'vara_file', inside
# the logs folder). Installation Manual §10.11 has the full setup.
#
# install.sh sets OUTPUT_FILE and installs vara-logger.service (runs as root,
# to read /var/log/syslog). By hand: edit OUTPUT_FILE, then
#   sudo cp vara-logger.service /etc/systemd/system/ && sudo systemctl daemon-reload
#   sudo systemctl enable --now vara-logger
#
# 2026-10-27: rsyslog on Debian 12 and Ubuntu 24.04 writes timestamps as
# 2026-10-27T13:13:23.123456-04:00; the dashboard's parsers read the
# traditional "Oct 27 13:13:23". Lines with the new form are rewritten to the
# traditional one (same local time) before they are saved; lines already in
# the traditional form are saved as they are.
#
# Check: sudo systemctl status vara-logger
# Watch: tail -f /var/www/bpq-dashboard/logs/yourcall.vara

# === CONFIGURATION (install.sh fills these in) ===
OUTPUT_FILE="/var/www/bpq-dashboard/logs/yourcall.vara"
# =================================================

LOGFILE="/var/log/syslog"
FILTER="VARAHF"

# Ensure output directory and file exist
mkdir -p "$(dirname "$OUTPUT_FILE")"
touch "$OUTPUT_FILE"

# tail -F follows through rotation; -n 0 = only NEW lines.
# "YYYY-MM-DDTHH:MM:SS[.frac][+-zz:zz] rest" becomes "Mon dd HH:MM:SS rest"
# (done in bash, line by line: awk would hold lines back in a pipe).
MON=(x Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec)
RE_ISO='^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}:[0-9]{2}:[0-9]{2})[^ ]*[ ](.*)$'
tail -F -n 0 "$LOGFILE" 2>/dev/null | grep --line-buffered "$FILTER" | while IFS= read -r line; do
    if [[ $line =~ $RE_ISO ]]; then
        printf -v line '%s %2d %s %s' "${MON[10#${BASH_REMATCH[2]}]}" "$((10#${BASH_REMATCH[3]}))" \
            "${BASH_REMATCH[4]}" "${BASH_REMATCH[5]}"
    fi
    # Only check the last 100 lines for duplicates (efficient, handles restarts)
    if ! tail -100 "$OUTPUT_FILE" 2>/dev/null | grep -Fxq -- "$line"; then
        printf '%s\n' "$line" >> "$OUTPUT_FILE"
    fi
done
