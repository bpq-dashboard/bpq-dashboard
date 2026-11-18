#!/usr/bin/env bash
# =============================================================================
# data2g-monitor.sh — switch on Data2G's own monitor page (v1.5.56)
#
# Data2G (github.com/arodland/Data2G, 0.5) comes as data2g-host (headless,
# what the data2g service runs) and data2g-web: the same modem plus a web
# page with a Monitor tab (waterfall, constellation, link state, every
# burst heard with its S/N, decoded packets) and a Settings tab. The page
# has a password and listens on 127.0.0.1 only.
#
# This script:
#   1. blocks the page's port from the network first (iptables, saved);
#   2. builds data2g-web from the Data2G source already on the machine
#      (/usr/local/src/data2g, from the install), adding Qt WebSockets, and
#      on Debian 12 (Qt 6.4) a one-line fix Data2G needs for Qt before 6.5;
#   3. asks for the page's password (typed twice, stored hashed in the
#      service user's ~/.config/Data2G/data2g.conf, made private);
#   4. waits for a Data2G session to finish, then switches the data2g
#      service from data2g-host to data2g-web with the same options plus
#      --web-listen 127.0.0.1 --web-port <port> (and TZ=UTC), restarts it and
#      checks LinBPQ attaches again (it reconnects by itself);
#   5. puts a "Data2G Monitor" launcher on that user's desktop.
#
#   sudo bash tools/data2g-monitor.sh               # port 8520
#   sudo bash tools/data2g-monitor.sh --port 8530
#   sudo bash tools/data2g-monitor.sh --now         # don't wait for a session to end
#   sudo bash tools/data2g-monitor.sh --password    # set a new page password only
#   sudo bash tools/data2g-monitor.sh --off         # back to data2g-host, no page
#
# The Monitor tab only watches (its Abort button ends a session). The
# Settings tab CHANGES Data2G (audio, levels, callsign ...): some at once,
# the rest at the next start, though the service's own options still win
# at every start. On a station LinBPQ drives, leave Settings alone.
# A backup of the service file is kept in /root. Safe to run again.
# =============================================================================
set -euo pipefail

PORT=8520; NOW=n; OFF=n; PWONLY=n
UNIT=/etc/systemd/system/data2g.service
SRC=/usr/local/src/data2g
LOG=/var/log/data2g/data2g.log
WEB=/usr/local/bin/data2g-web
HOST=/usr/local/bin/data2g-host
VERFILE=/usr/local/share/data2g/VERSION
while (( $# )); do
    case "$1" in
        --port) shift; PORT="${1:-}";;
        --now) NOW=y;;
        --off) OFF=y;;
        --password) PWONLY=y;;
        --unit) shift; UNIT="${1:-}";;
        --src) shift; SRC="${1:-}";;
        -h|--help) sed -n 2,36p "$0"; exit 0;;
        *) echo "Unknown option: $1" >&2; exit 2;;
    esac
    shift
done
say()  { echo "  $*"; }
die()  { echo; echo "Stopped: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "run it with sudo"
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT > 1023 && PORT < 65536 )) || die "--port needs a number 1024-65535"
[[ -f "$UNIT" ]] || die "no $UNIT (is Data2G installed as a service?)"
command -v python3 >/dev/null || die "python3 is needed"
RUNUSER=$(systemctl show -p User --value data2g); [[ -n "$RUNUSER" ]] || die "data2g.service has no User="
RUNHOME=$(getent passwd "$RUNUSER" | cut -d: -f6)
CONF="$RUNHOME/.config/Data2G/data2g.conf"
TNC=$(tr '\n' ' ' < "$UNIT" | grep -oE -- '--command-port[ =][0-9]+' | grep -oE '[0-9]+$' || echo 8300)

has_password() { [[ -f "$CONF" ]] && grep -qE '^password=.+' "$CONF"; }
set_password() {
    [[ -x "$WEB" ]] || die "data2g-web is not built yet (run this script without --password first)"
    [[ -t 0 ]] || die "the password is typed at a terminal: run this script in a terminal (ssh or the desktop's)"
    echo
    echo "  Choose a password for the Data2G monitor page (typed twice, not shown)."
    echo "  Don't reuse a password you use elsewhere; it is only for this page."
    sudo -u "$RUNUSER" -H "$WEB" --set-password || die "password not set"
    chmod 600 "$CONF"; chown "$RUNUSER": "$CONF"
    say "password saved (hashed) in $CONF, readable by $RUNUSER only"
}

if [[ $PWONLY == y ]]; then
    set_password
    systemctl is-active -q data2g && grep -q 'data2g-web' "$UNIT" && {
        say "the page asks for the new password from now on (open sessions were logged out)"; }
    exit 0
fi

echo
echo "Data2G monitor: $([[ $OFF == y ]] && echo 'switch OFF (back to data2g-host)' || echo "switch ON, port $PORT")"

# ── 1. Firewall first ────────────────────────────────────────────────────────
if [[ $OFF == n ]]; then
    owner=$(ss -ltnpH "sport = :$PORT" 2>/dev/null | grep -o 'users:(("[^"]*' | head -1 | sed 's/.*"//' || true)
    [[ -z "$owner" || "$owner" == data2g-web ]] || die "port $PORT is already used by '$owner'; choose another with --port"
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

# ── 2. Build data2g-web ─────────────────────────────────────────────────────
if [[ $OFF == n ]]; then
    [[ -d "$SRC/native" ]] || die "no Data2G source in $SRC (the install script puts it there; --src DIR)"
    ver=$(git -C "$SRC" describe --tags 2>/dev/null || echo '?')
    if [[ -x "$WEB" && -f "$VERFILE" && "$(cat "$VERFILE")" == "$ver" && "$WEB" -nt "$HOST" ]]; then
        say "data2g-web $ver already built"
    else
        say "building data2g-web $ver (Qt WebSockets; a few minutes) ..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq qt6-websockets-dev >/dev/null
        qtv=$(dpkg-query -W -f='${Version}' qt6-base-dev 2>/dev/null || echo 6.5)
        if dpkg --compare-versions "$qtv" lt 6.5 && grep -q '&QWebSocket::errorOccurred,' "$SRC/native/app/tci.cpp"; then
            # QWebSocket::errorOccurred is Qt 6.5's name for the signal Qt 6.4 calls error()
            sed -i 's/&QWebSocket::errorOccurred,/QOverload<QAbstractSocket::SocketError>::of(\&QWebSocket::error),/' "$SRC/native/app/tci.cpp"
            say "Qt $qtv: applied the Qt 6.4 fix to app/tci.cpp"
        fi
        B="$SRC/native/build-bpq-web"
        rm -rf "$B"
        cmake -S "$SRC/native" -B "$B" -G Ninja -DCMAKE_BUILD_TYPE=Release \
            -DDATA2G_AUDIO_BACKEND=miniaudio -DDATA2G_BUILD_RIG=OFF -DDATA2G_BUILD_GUI=OFF \
            -DDATA2G_BUILD_PYMODULE=OFF -DDATA2G_BUILD_TESTS=OFF >"$SRC/build-web.log" 2>&1 \
            || { tail -20 "$SRC/build-web.log"; die "cmake failed (full log: $SRC/build-web.log)"; }
        nice cmake --build "$B" --target data2g-web --parallel "$(nproc)" >>"$SRC/build-web.log" 2>&1 \
            || { grep -E 'error' "$SRC/build-web.log" | head -10; die "build failed (full log: $SRC/build-web.log)"; }
        install -m 755 "$B/data2g-web" "$WEB"
        install -d -m 755 "$(dirname "$VERFILE")"; echo "$ver" > "$VERFILE"; chmod 644 "$VERFILE"
        say "installed $WEB"
    fi
    if has_password; then
        chmod 600 "$CONF"
        say "page password: already set (sudo bash $0 --password to change it)"
    else
        set_password
    fi
fi

# ── 3. Wait for a Data2G session to finish ──────────────────────────────────
in_session() {
    [[ -r "$LOG" ]] || return 1
    local last
    last=$(grep -aE 'INFO (RX CONNECT_ACK|TX CONNECT_ACK|DISCONNECTED|command: ABORT|command client gone|commands on)' "$LOG" 2>/dev/null | tail -1 || true)
    [[ "$last" == *"CONNECT_ACK"* ]]
}
if [[ $NOW == n ]] && in_session; then
    say "Data2G is in a session; waiting for it to end (up to 15 min, --now to skip) ..."
    for _ in $(seq 1 90); do in_session || break; sleep 10; done
    in_session && die "still in a session after 15 min; run again later or use --now"
fi

# ── 4. The service: data2g-host <-> data2g-web ──────────────────────────────
BK="/root/data2g.service.before-monitor-$(date +%Y%m%d-%H%M%S)"
cp -p "$UNIT" "$BK"
python3 - "$UNIT" "$OFF" "$PORT" "$HOST" "$WEB" <<'PY'
import re, sys
unit, off, port, host, web = sys.argv[1], sys.argv[2] == 'y', sys.argv[3], sys.argv[4], sys.argv[5]
s = open(unit).read()
m = re.search(r'^ExecStart=(?:.*\\\n)*.*$', s, re.M)
if not m:
    sys.exit('no ExecStart= in ' + unit)
ex = m.group(0)
ex = re.sub(r'\s--web-(?:listen|port)[ =]\S+', '', ex)          # drop old page options
ex = re.sub(r'(?<=ExecStart=)\S*data2g-(?:host|web)\b', host if off else web, ex)
if not off:
    ex = re.sub(r'(?<=ExecStart=)(\S+)', r'\1 --web-listen 127.0.0.1 --web-port ' + port, ex, count=1)
s = s[:m.start()] + ex + s[m.end():]
s = re.sub(r'^(Description=.*?)(?: \+ monitor page.*)?$', lambda x: x.group(1) + ('' if off else ' + monitor page 127.0.0.1:' + port), s, count=1, flags=re.M)
if not re.search(r'^Environment=.*\bTZ=', s, re.M):              # Data2G logs local time; the dashboard reads UTC
    s = re.sub(r'^(\[Service\]\n)', r'\1Environment=TZ=UTC\n', s, count=1, flags=re.M)
open(unit, 'w').write(s)
PY
say "service: $(grep -E '^ExecStart=' "$UNIT" | cut -c1-110)..."
say "backup: $BK"
systemctl daemon-reload
systemctl restart data2g
say "Data2G restarted; waiting for LinBPQ to reconnect ..."
ok=n
for _ in $(seq 1 30); do
    if ss -tnH state established "( sport = :$TNC )" 2>/dev/null | grep -q .; then ok=y; break; fi
    sleep 2
done
[[ $ok == y ]] && say "LinBPQ attached to Data2G again (port $TNC)" \
               || say "WARNING: LinBPQ has not reconnected after 60 s. Check: sudo tail -5 $LOG; if needed: sudo systemctl restart bpq"
systemctl is-active -q data2g || { tail -15 "$LOG"; echo; echo "Data2G did not start: put the old service back with"; echo "  sudo cp -p $BK $UNIT && sudo systemctl daemon-reload && sudo systemctl restart data2g"; exit 1; }

if [[ $OFF == y ]]; then
    echo; echo "Monitor switched off: the service runs data2g-host again. (The firewall rule for port $PORT can stay.)"
    exit 0
fi

# ── 5. Check the page answers, here only ────────────────────────────────────
sleep 2
code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/" || true)
[[ "$code" == 200 ]] && say "monitor answers: http://127.0.0.1:$PORT/" \
                     || say "WARNING: monitor did not answer (HTTP '$code'). sudo grep -i 'web UI' $LOG | tail -3"

# ── 6. Desktop launcher ─────────────────────────────────────────────────────
D=$(sudo -u "$RUNUSER" xdg-user-dir DESKTOP 2>/dev/null || true)
[[ -n "$D" && "$D" != "$RUNHOME" ]] || D="$RUNHOME/Desktop"
if [[ -d "$RUNHOME" ]]; then
    sudo -u "$RUNUSER" mkdir -p "$D"
    F="$D/data2g-monitor.desktop"
    cat > "$F" <<EOF
[Desktop Entry]
Type=Application
Name=Data2G Monitor
Comment=Data2G HF modem: waterfall, bursts and S/N, link (log in with the page password)
Exec=xdg-open http://127.0.0.1:$PORT/
Icon=utilities-system-monitor
Terminal=false
Categories=HamRadio;Network;
EOF
    chown "$RUNUSER": "$F"; chmod 755 "$F"
    sudo -u "$RUNUSER" gio set "$F" metadata::trusted true >/dev/null 2>&1 || true
    sum=$(sha256sum "$F" | cut -d' ' -f1)
    sudo -u "$RUNUSER" gio set -t string "$F" metadata::xfce-exe-checksum "$sum" >/dev/null 2>&1 || true
    say "desktop launcher: $F"
fi

echo
echo "Done. On the station's desktop (VNC), double-click 'Data2G Monitor' and log in."
echo "Monitor tab: watch only (its Abort button ends a session). Settings tab: changes Data2G; leave it alone."
echo "Undo: sudo bash $0 --off    (or: sudo cp -p $BK $UNIT && sudo systemctl daemon-reload && sudo systemctl restart data2g)"
