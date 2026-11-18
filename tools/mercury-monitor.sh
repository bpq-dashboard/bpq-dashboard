#!/usr/bin/env bash
# =============================================================================
# mercury-monitor.sh — switch on Mercury's own monitor page (v1.5.49)
#
# Mercury (Rhizomatica, 1.9.x) can serve a small web monitor of itself:
#   http://127.0.0.1:<port>/waterfall.html   watch only: waterfall, S/N,
#        bitrate, direction, sync, callsigns, TX/RX. Sends nothing to Mercury.
#   http://127.0.0.1:<port>/mercury.html     the full page: it can CHANGE
#        Mercury's audio devices, PTT and TX level. Leave it alone on a
#        station where LinBPQ drives Mercury.
#
# Mercury opens that port on every network interface with no password, so
# this script blocks it from the network FIRST (iptables, saved), then turns
# it on in mercury.ini ([main] ui_enabled / ui_port / waterfall_enabled),
# restarts Mercury (LinBPQ reconnects by itself), checks it, and puts a
# "Mercury Monitor" launcher on the desktop of the user Mercury runs as.
#
#   sudo bash tools/mercury-monitor.sh               # port 8403, waterfall on
#   sudo bash tools/mercury-monitor.sh --port 8410
#   sudo bash tools/mercury-monitor.sh --no-waterfall   # status only, less CPU
#   sudo bash tools/mercury-monitor.sh --now         # don't wait for a session to end
#   sudo bash tools/mercury-monitor.sh --off         # switch the monitor off again
#
# Port 10000 (Mercury's default) is avoided because Webmin uses it.
# A backup of mercury.ini is kept in /root. Safe to run again.
# =============================================================================
set -euo pipefail

PORT=8403; WF=true; NOW=n; OFF=n
INI=/etc/mercury/mercury.ini
LOG=/var/log/mercury/mercury.log
while (( $# )); do
    case "$1" in
        --port) shift; PORT="${1:-}";;
        --no-waterfall) WF=false;;
        --now) NOW=y;;
        --off) OFF=y;;
        --ini) shift; INI="${1:-}";;
        -h|--help) sed -n 2,26p "$0"; exit 0;;
        *) echo "Unknown option: $1" >&2; exit 2;;
    esac
    shift
done
say()  { echo "  $*"; }
die()  { echo; echo "Stopped: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "run it with sudo"
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT > 1023 && PORT < 65536 )) || die "--port needs a number 1024-65535"
[[ -f "$INI" ]] || die "no $INI (is Mercury installed as a service?)"
command -v python3 >/dev/null || die "python3 is needed"
systemctl cat mercury >/dev/null 2>&1 || die "no mercury.service"

# The port must be free (Mercury's own, or nothing)
if [[ $OFF == n ]]; then
    owner=$(ss -ltnpH "sport = :$PORT" 2>/dev/null | grep -o 'users:(("[^"]*' | head -1 | sed 's/.*"//' || true)
    [[ -z "$owner" || "$owner" == mercury ]] || die "port $PORT is already used by '$owner'; choose another with --port"
fi

echo
echo "Mercury monitor: $([[ $OFF == y ]] && echo 'switch OFF' || echo "switch ON, port $PORT, waterfall $WF")"

# ── 1. Firewall first ────────────────────────────────────────────────────────
if [[ $OFF == n ]]; then
    if iptables -C INPUT ! -i lo -p tcp --dport "$PORT" -j DROP 2>/dev/null; then
        say "firewall: port $PORT already blocked from the network"
    else
        iptables -I INPUT 1 ! -i lo -p tcp --dport "$PORT" -j DROP
        say "firewall: port $PORT blocked from the network (only this machine can open it)"
    fi
    if command -v netfilter-persistent >/dev/null; then
        netfilter-persistent save >/dev/null 2>&1 && say "firewall: saved (netfilter-persistent)"
    else
        say "WARNING: netfilter-persistent not installed: the block is lost at reboot. sudo apt install iptables-persistent"
    fi
fi

# ── 2. Wait for a Mercury session to finish ─────────────────────────────────
in_session() {
    [[ -r "$LOG" ]] || return 1
    local last
    last=$(grep -aE '\] (Connected to |Disconnected)' "$LOG" 2>/dev/null | tail -1 || true)
    [[ "$last" == *"Connected to "* ]]
}
if [[ $NOW == n ]] && in_session; then
    say "Mercury is in a session; waiting for it to end (up to 15 min, --now to skip) ..."
    for _ in $(seq 1 90); do in_session || break; sleep 10; done
    in_session && die "still in a session after 15 min; run again later or use --now"
fi

# ── 3. mercury.ini ──────────────────────────────────────────────────────────
BK="/root/mercury.ini.before-monitor-$(date +%Y%m%d-%H%M%S)"
cp -p "$INI" "$BK"
python3 - "$INI" "$OFF" "$PORT" "$WF" <<'PY'
import sys, re
ini, off, port, wf = sys.argv[1], sys.argv[2] == 'y', sys.argv[3], sys.argv[4]
want = {'ui_enabled': 'false'} if off else {
    'ui_enabled': 'true', 'ui_port': port, 'ui_protocol': 'ws', 'waterfall_enabled': wf}
lines = open(ini).read().split('\n')
sec, main_at, seen = None, None, set()
for i, l in enumerate(lines):
    m = re.match(r'^\s*\[([^\]]+)\]', l)
    if m:
        sec = m.group(1).strip().lower()
        if sec == 'main' and main_at is None:
            main_at = i
        continue
    m = re.match(r'^\s*([A-Za-z_]+)\s*=', l)
    if m and sec == 'main' and m.group(1) in want:
        lines[i] = f"{m.group(1)} = {want[m.group(1)]}"
        seen.add(m.group(1))
add = [f"{k} = {v}" for k, v in want.items() if k not in seen]
if add:
    block = ['; Mercury monitor (BPQ Dashboard tools/mercury-monitor.sh)'] + add
    if main_at is None:
        lines = ['[main]'] + block + [''] + lines
    else:
        lines[main_at + 1:main_at + 1] = block
open(ini, 'w').write('\n'.join(lines))
PY
say "mercury.ini: $(grep -E '^\s*(ui_enabled|ui_port|ui_protocol|waterfall_enabled)\s*=' "$INI" | tr '\n' ' ')"
say "backup: $BK"

# ── 4. Restart Mercury; LinBPQ reconnects ───────────────────────────────────
systemctl restart mercury
say "Mercury restarted; waiting for LinBPQ to reconnect ..."
tnc=$(grep -E '^\s*arq_tcp_base_port\s*=' "$INI" | head -1 | grep -oE '[0-9]+' || echo 8400)
ok=n
for _ in $(seq 1 30); do
    if ss -tnH state established "( sport = :$tnc )" 2>/dev/null | grep -q .; then ok=y; break; fi
    sleep 2
done
[[ $ok == y ]] && say "LinBPQ attached to Mercury again (port $tnc)" \
               || say "WARNING: LinBPQ has not reconnected after 60 s. Check: sudo tail -5 $LOG; if needed: sudo systemctl restart bpq"

if [[ $OFF == y ]]; then
    echo; echo "Monitor switched off. (The firewall rule for its port can stay.)"; exit 0
fi

# ── 5. Check the monitor answers, here only ─────────────────────────────────
sleep 1
code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/waterfall.html" || true)
[[ "$code" == 200 ]] && say "monitor answers: http://127.0.0.1:$PORT/waterfall.html" \
                     || say "WARNING: monitor did not answer (HTTP '$code'). sudo grep -i websocket $LOG | tail -3"

# ── 6. Desktop launcher for the user Mercury runs as ────────────────────────
U=$(systemctl show -p User --value mercury); [[ -n "$U" ]] || U=root
H=$(getent passwd "$U" | cut -d: -f6)
D=$(sudo -u "$U" xdg-user-dir DESKTOP 2>/dev/null || true)
[[ -n "$D" && "$D" != "$H" ]] || D="$H/Desktop"
if [[ -d "$H" ]]; then
    sudo -u "$U" mkdir -p "$D"
    F="$D/mercury-monitor.desktop"
    cat > "$F" <<EOF
[Desktop Entry]
Type=Application
Name=Mercury Monitor
Comment=Mercury HF modem: waterfall, S/N, TX/RX (watch only)
Exec=xdg-open http://127.0.0.1:$PORT/waterfall.html
Icon=utilities-system-monitor
Terminal=false
Categories=HamRadio;Network;
EOF
    chown "$U": "$F"; chmod 755 "$F"
    # XFCE asks before running a new launcher unless it is marked trusted
    sudo -u "$U" gio set "$F" metadata::trusted true >/dev/null 2>&1 || true
    sum=$(sha256sum "$F" | cut -d' ' -f1)
    sudo -u "$U" gio set -t string "$F" metadata::xfce-exe-checksum "$sum" >/dev/null 2>&1 || true
    say "desktop launcher: $F"
fi

echo
echo "Done. On the station's desktop (VNC), double-click 'Mercury Monitor'."
echo "Watch-only page: http://127.0.0.1:$PORT/waterfall.html"
echo "Full page (can change Mercury's settings): http://127.0.0.1:$PORT/mercury.html"
echo "Undo: sudo bash $0 --off    (or: sudo cp -p $BK $INI && sudo systemctl restart mercury)"
