#!/bin/bash
# ============================================================================
#  BPQ Dashboard — basic firewall (ufw) for a packet station   (v1.5.19)
#
#  Run as root, any time, as often as you like:
#      sudo bash /var/www/bpq-dashboard/tools/setup-firewall.sh
#      sudo bash /var/www/bpq-dashboard/tools/setup-firewall.sh --dry-run
#
#  It asks before deciding anything, shows the complete rule list and asks
#  again before switching the firewall on. The address of the SSH session
#  you are using is always allowed, so running it over SSH can't lock you
#  out. If ufw is already on, it only adds the rules that are missing and
#  changes nothing else.
#
#  --dry-run   print the rules it would add, change nothing
#  --yes       accept the suggested answer to every question (for scripts);
#              still never opens BPQ's ports to the internet unless asked
#
#  Undo:  sudo ufw disable            (firewall off, rules kept)
#         sudo ufw reset              (all rules removed)
# ============================================================================
set -u

DRY=n; AUTO=n
for a in "$@"; do
    case "$a" in
        --dry-run) DRY=y ;;
        --yes|-y)  AUTO=y ;;
        -h|--help) sed -n 2,21p "$0"; exit 0 ;;
        *) echo "Unknown option: $a (try --help)"; exit 2 ;;
    esac
done

if [[ -t 1 ]]; then B=$'\033[1m'; Y=$'\033[0;33m'; G=$'\033[0;32m'; R=$'\033[0;31m'; D=$'\033[2m'; N=$'\033[0m'
else B=''; Y=''; G=''; R=''; D=''; N=''; fi
say()  { echo "  $*"; }
note() { echo "${D}  $*${N}"; }
ask()  { echo; echo "${B}${Y}▶ $*${N}"; }
# choose "question" default(1|2) -> echoes 1 or 2
choose() {
    local q="$1" d="$2" a
    if [[ "$AUTO" == "y" ]]; then echo "$d"; return; fi
    read -r -p "  $q [$d]: " a </dev/tty
    a="${a:-$d}"; [[ "$a" == "2" ]] && echo 2 || echo 1
}
yes_no() {   # yes_no "question" Y|N
    local d="${2:-N}" p="[y/N]" a; [[ "$d" == "Y" ]] && p="[Y/n]"
    if [[ "$AUTO" == "y" ]]; then [[ "$d" == "Y" ]]; return; fi
    read -r -p "  $1 $p: " a </dev/tty
    if [[ "$d" == "Y" ]]; then [[ ! "$a" =~ ^[Nn] ]]; else [[ "$a" =~ ^[Yy] ]]; fi
}

[[ $EUID -eq 0 ]] || { echo "Run as root: sudo bash $0"; exit 1; }

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_ROOT="$(dirname "$HERE")"
CFG="$WEB_ROOT/config.php"

echo
echo "${B}BPQ Dashboard — firewall setup${N} $( [[ "$DRY" == "y" ]] && echo "(dry run: nothing is changed)" )"
echo

# ── ufw ──────────────────────────────────────────────────────────────────
if ! command -v ufw >/dev/null 2>&1; then
    if [[ "$DRY" == "y" ]]; then
        say "ufw is not installed (it would be installed with: apt-get install ufw)."
    elif yes_no "ufw (Uncomplicated Firewall) is not installed. Install it now?" Y; then
        apt-get install -y -qq ufw || { echo "${R}Could not install ufw.${N}"; exit 1; }
    else
        echo "Nothing done."; exit 0
    fi
fi
UFW_ACTIVE=n
command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active" && UFW_ACTIVE=y
[[ "$UFW_ACTIVE" == "y" ]] && say "ufw is already ${G}on${N}: only missing rules are added, nothing else changes."

# ── What this station uses ────────────────────────────────────────────────
php_cfg() {   # php_cfg section key default
    [[ -r "$CFG" ]] && command -v php >/dev/null 2>&1 || { echo "$3"; return; }
    php -r '$c = @include $argv[1]; $v = is_array($c) ? ($c[$argv[2]][$argv[3]] ?? "") : ""; echo is_scalar($v) ? $v : "";' -- "$CFG" "$1" "$2" 2>/dev/null | grep . || echo "$3"
}
LINBPQ=$(php_cfg paths linbpq /opt/linbpq); LINBPQ="${LINBPQ%/}"
BPQCFG="$LINBPQ/bpq32.cfg"
cfgnum() { [[ -r "$BPQCFG" ]] && grep -ioE "^[[:space:]]*$1[[:space:]]*=[[:space:]]*[0-9]+" "$BPQCFG" | grep -oE '[0-9]+$' | head -1; }
TELNET=$(php_cfg bbs port ""); [[ -n "$TELNET" ]] || TELNET=$(cfgnum TCPPORT); TELNET="${TELNET:-8010}"
FBB=$(php_cfg bbs fbb_port ""); [[ -n "$FBB" ]] || FBB=$(cfgnum FBBPORT); FBB="${FBB:-8011}"
HTTPP=$(php_cfg bbs http_port ""); [[ -n "$HTTPP" ]] || HTTPP=$(cfgnum HTTPPORT); HTTPP="${HTTPP:-8008}"
# AXIP / AXUDP: "UDP nnnn" lines in BPQAXIP port configs (NetRom links over the internet)
AXUDP=""
[[ -r "$BPQCFG" ]] && AXUDP=$(grep -iE '^[[:space:]]*UDP[[:space:]]+[0-9]+' "$BPQCFG" | grep -oE '[0-9]+' | sort -un | tr '\n' ' ')
# SSH ports sshd really listens on
SSHP=$(sshd -T 2>/dev/null | awk '$1=="port"{print $2}' | sort -un | tr '\n' ' ')
SSHP="${SSHP:-22}"
# Web ports nginx / Apache listen on
WEBP=$(ss -Hltnp 2>/dev/null | grep -E '"(nginx|apache2|httpd)"' | awk '{print $4}' | grep -oE '[0-9]+$' | sort -un | tr '\n' ' ')
WEBP="${WEBP:-80 443}"
# VARA HF syslog senders (install.sh's rsyslog receiver)
VARA_FROM=""
[[ -r /etc/rsyslog.d/10-bpq-dashboard-vara.conf ]] && \
    VARA_FROM=$(grep -i '^\$AllowedSender' /etc/rsyslog.d/10-bpq-dashboard-vara.conf | sed 's/^[^,]*,//; s/,/ /g' | tr -s ' ' '\n' | grep -vE '^(127\.0\.0\.1|)$' | tr '\n' ' ')
# The SSH session running this script
SESSION_IP=$(echo "${SSH_CONNECTION:-}" | awk '{print $1}')

# Home network: from the interface of the default route
LAN=""
IFACE=$(ip -4 route show default 2>/dev/null | awk '{for(i=1;i<NF;i++) if($i=="dev"){print $(i+1); exit}}')
if [[ -n "$IFACE" ]]; then
    ADDR=$(ip -o -4 addr show dev "$IFACE" 2>/dev/null | awk '{print $4; exit}')
    [[ -n "$ADDR" ]] && LAN=$(python3 -c 'import ipaddress,sys; print(ipaddress.ip_interface(sys.argv[1]).network)' "$ADDR" 2>/dev/null)
fi

say "Station ports found:"
note "SSH: $SSHP   web (dashboard): $WEBP   BPQ telnet: $TELNET   FBB: $FBB   BPQ web: $HTTPP"
[[ -n "$AXUDP" ]] && note "BPQ AXIP/AXUDP (UDP): $AXUDP"
[[ -n "$VARA_FROM" ]] && note "VARA HF syslog (UDP 514) from: $VARA_FROM"
[[ -n "$SESSION_IP" ]] && note "Your SSH session comes from: $SESSION_IP (always allowed)"
[[ -r "$BPQCFG" ]] || note "(bpq32.cfg not found at $BPQCFG: BPQ ports from config.php / defaults)"

# ── Questions ────────────────────────────────────────────────────────────
ask "Your home network is $( [[ -n "$LAN" ]] && echo "$LAN" || echo "not detected" ). Is that right?"
note "Machines on it (your PCs, the VARA computer) are treated as trusted."
if [[ -z "$LAN" ]] || ! yes_no "Use $LAN?" Y; then
    if [[ "$AUTO" == "y" ]]; then echo "No home network detected; give it without --yes."; exit 1; fi
    read -r -p "  Home network (e.g. 192.168.1.0/24): " LAN </dev/tty
    python3 -c 'import ipaddress,sys; ipaddress.ip_network(sys.argv[1], strict=False)' "$LAN" 2>/dev/null \
        || { echo "${R}'$LAN' is not a network like 192.168.1.0/24.${N}"; exit 1; }
    LAN=$(python3 -c 'import ipaddress,sys; print(ipaddress.ip_network(sys.argv[1], strict=False))' "$LAN")
fi

ask "SSH (remote login, port $SSHP): who may connect?"
note "1 = home network only   2 = anywhere (you log in from outside; fail2ban recommended)"
SSH_W=$(choose "Choose 1 or 2" 1)

ask "The dashboard web pages (port $WEBP): who may open them?"
note "1 = home network only   2 = anywhere (public dashboard; the admin login protects the rest)"
WEB_W=$(choose "Choose 1 or 2" 1)

ask "BPQ telnet ($TELNET) and FBB ($FBB): who may connect?"
note "1 = home network only   2 = anywhere (forwarding partners or users connect in over the internet)"
note "These send passwords in clear text: choose 2 only if partners really connect in this way."
BPQ_W=$(choose "Choose 1 or 2" 1)
note "BPQ's web management page ($HTTPP) is always home network only."

# ── Rules ────────────────────────────────────────────────────────────────
RULES=()   # each: "ufw arguments|why"
add() { RULES+=("$1|$2"); }
for p in $SSHP; do
    [[ -n "$SESSION_IP" ]] && add "allow from $SESSION_IP to any port $p proto tcp comment 'SSH (this session)'" "SSH from your current session"
    if [[ "$SSH_W" == "2" ]]; then add "allow $p/tcp comment 'SSH'" "SSH from anywhere"
    else add "allow from $LAN to any port $p proto tcp comment 'SSH (home)'" "SSH from the home network"; fi
done
for p in $WEBP; do
    if [[ "$WEB_W" == "2" ]]; then add "allow $p/tcp comment 'BPQ Dashboard web'" "dashboard web pages from anywhere"
    else add "allow from $LAN to any port $p proto tcp comment 'BPQ Dashboard web (home)'" "dashboard web pages from the home network"; fi
done
for p in $TELNET $FBB; do
    if [[ "$BPQ_W" == "2" ]]; then add "allow $p/tcp comment 'BPQ telnet/FBB'" "BPQ port $p from anywhere"
    else add "allow from $LAN to any port $p proto tcp comment 'BPQ telnet/FBB (home)'" "BPQ port $p from the home network"; fi
done
add "allow from $LAN to any port $HTTPP proto tcp comment 'BPQ web management (home)'" "BPQ web management from the home network"
for v in $VARA_FROM; do add "allow from $v to any port 514 proto udp comment 'VARA HF syslog'" "VARA HF log from $v"; done
for u in $AXUDP; do add "allow $u/udp comment 'BPQ AXIP/AXUDP'" "BPQ AXIP link port $u (partners connect in)"; done

echo
echo "${B}Rules$( [[ "$UFW_ACTIVE" == "y" ]] && echo " to add (existing ones are skipped by ufw)" ):${N}"
[[ "$UFW_ACTIVE" == "y" ]] || note "Default: everything else coming IN is refused; everything going OUT is allowed (APRS-IS, NWS, Winlink, updates)."
for r in "${RULES[@]}"; do printf "  ufw %-75s %s\n" "${r%%|*}" "${D}# ${r#*|}${N}"; done
note "IPv6: rules for 'anywhere' cover IPv6 too; home-network rules are IPv4."

if [[ "$DRY" == "y" ]]; then echo; echo "Dry run: nothing changed."; exit 0; fi

echo
if ! yes_no "Apply these rules$( [[ "$UFW_ACTIVE" == "y" ]] || echo " and switch the firewall on" )?" N; then
    echo "Nothing changed."; exit 0
fi
sed -i 's/^IPV6=.*/IPV6=yes/' /etc/default/ufw 2>/dev/null
if [[ "$UFW_ACTIVE" != "y" ]]; then
    ufw default deny incoming >/dev/null
    ufw default allow outgoing >/dev/null
fi
FAILS=0
for r in "${RULES[@]}"; do
    eval "ufw ${r%%|*}" >/dev/null 2>&1 || { echo "${R}  failed: ufw ${r%%|*}${N}"; FAILS=$((FAILS+1)); }
done
if [[ "$UFW_ACTIVE" != "y" ]]; then
    ufw --force enable >/dev/null && echo "${G}  Firewall is on.${N}" || { echo "${R}  ufw enable failed.${N}"; exit 1; }
else
    ufw reload >/dev/null 2>&1
    echo "${G}  Rules added.${N}"
fi
(( FAILS )) && echo "${Y}  $FAILS rule(s) failed; see above.${N}"
ufw status numbered | sed 's/^/  /'

# fail2ban (SSH brute-force protection)
if ! command -v fail2ban-client >/dev/null 2>&1; then
    echo
    if yes_no "Install fail2ban too (blocks addresses that keep guessing SSH passwords)?" "$( [[ "$SSH_W" == "2" ]] && echo Y || echo N )"; then
        apt-get install -y -qq fail2ban && systemctl enable --now fail2ban >/dev/null 2>&1 && echo "${G}  fail2ban is on (SSH jail).${N}"
    fi
fi

cat <<EOF

  Check it: the dashboard's Admin > Firewall and System Audit pages.
  Undo:     sudo ufw disable    (off, rules kept)    sudo ufw reset    (all rules removed)
  Re-run this script any time; it only adds what's missing.
EOF
