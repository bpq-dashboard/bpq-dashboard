#!/usr/bin/env bash
# =============================================================================
# bbs-mail-setup.sh — BBS mail in any mail app (IMAP), optional (v1.5.67)
#
# Sets up a small mail store on this computer that holds a copy of your BBS
# mail, so Thunderbird, a phone's mail app (and later the dashboard's Email
# Client) can read it with folders, read marks and search:
#
#     INBOX              personal and NTS mail to your callsign (or SYSOP)
#     Sent               personal and NTS mail from your callsign
#     Bulletins/<GROUP>  every bulletin, one folder per group, kept 90 days
#
# The BBS stays the master copy: this only copies, it never changes the BBS.
# Mail is read over encrypted IMAP (port 993) and only from your own network
# (logins from other addresses are refused). Nothing is ever sent from here:
# there is no outgoing mail server, and nothing listens on port 25.
#
# What it does:
#   1. installs Dovecot (the IMAP server: apt package dovecot-imapd);
#   2. creates the user bpqmail that owns the mail (/var/mail/bpq-dashboard);
#   3. writes its settings to /etc/dovecot/conf.d/99-bpq-dashboard.conf and
#      turns off logins with this computer's own accounts (only the mail
#      login below works);
#   4. asks you for the mail password (typed twice at the terminal, stored
#      hashed in /etc/dovecot/bpq-dashboard.passwd). Use a NEW password,
#      not your BBS or computer password;
#   5. uses your Let's Encrypt certificate when there is one (and reloads
#      Dovecot when it renews), otherwise Dovecot's own (mail apps will ask
#      you to accept it once);
#   6. starts bbs-mail-sync.timer: new BBS mail is copied every 2 minutes.
#
#   sudo bash tools/bbs-mail-setup.sh                   set up (or check / repair)
#   sudo bash tools/bbs-mail-setup.sh --dry-run         show what it would do
#   sudo bash tools/bbs-mail-setup.sh --domain NAME     which certificate (when you have several)
#   sudo bash tools/bbs-mail-setup.sh --password        set a new mail password only
#   sudo bash tools/bbs-mail-setup.sh --status          what's running, folders, counts
#   sudo bash tools/bbs-mail-setup.sh --remove          stop and remove the settings
#                                                       (the copied mail is kept)
#   sudo bash tools/bbs-mail-setup.sh --remove --purge  ... and delete the copied mail
#
# Mail app settings:  server = the certificate's name (or this computer's
# address), IMAP, port 993, SSL/TLS, normal password, user = your callsign
# in lower case. Safe to run again.
# =============================================================================
set -euo pipefail

DRY=n; DOMAIN=""; PWONLY=n; STATUS=n; REMOVE=n; PURGE=n; FORCE=n
while (( $# )); do
    case "$1" in
        --dry-run) DRY=y;;
        --domain) shift; DOMAIN="${1:-}";;
        --password) PWONLY=y;;
        --status) STATUS=y;;
        --remove) REMOVE=y;;
        --purge) PURGE=y;;
        --force) FORCE=y;;
        -h|--help) sed -n 2,44p "$0"; exit 0;;
        *) echo "Unknown option: $1 (try --help)" >&2; exit 2;;
    esac
    shift
done

if [[ -t 1 ]]; then B=$'\033[1m'; G=$'\033[0;32m'; Y=$'\033[0;33m'; D=$'\033[2m'; N=$'\033[0m'
else B=''; G=''; Y=''; D=''; N=''; fi
say()  { echo "  $*"; }
ok()   { echo "  ${G}✓${N} $*"; }
warn() { echo "  ${Y}!${N} $*"; }
die()  { echo; echo "Stopped: $*" >&2; exit 1; }
run()  { if [[ $DRY == y ]]; then echo "  ${D}would run: $*${N}"; else "$@"; fi; }

[[ $EUID -eq 0 ]] || die "run it with sudo"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_ROOT="$(dirname "$HERE")"
CFG="$WEB_ROOT/config.php"
SYNC="$WEB_ROOT/scripts/bbs-mail-sync.py"
DROPIN=/etc/dovecot/conf.d/99-bpq-dashboard.conf
PASSWD=/etc/dovecot/bpq-dashboard.passwd
AUTHCONF=/etc/dovecot/conf.d/10-auth.conf
STORE=/var/mail/bpq-dashboard
MUSER=bpqmail
HOOK=/etc/letsencrypt/renewal-hooks/deploy/bpq-dashboard-dovecot.sh
MARK="# BPQ Dashboard BBS mail store"
LAN_NETS="127.0.0.0/8,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7,fe80::/10"
MASTERPW=/etc/dovecot/bpq-dashboard-master.passwd   # the dashboard's own login (v1.5.68)
SECRET_DIR=/etc/bpq-dashboard
SECRET=$SECRET_DIR/bbs-mail.php                    # what the Email Client uses to read the mail store
IPT_COMMENT="BPQ Dashboard BBS mail (LAN)"

[[ -f "$CFG" ]] || die "no $CFG (run this from the dashboard's tools folder)"
[[ -f "$SYNC" ]] || die "no $SYNC (update the dashboard to 1.5.74 or later first)"

# Settings from config.php (no secrets read here)
php_val() { php -r '$c = @include $argv[1]; $v = $c; foreach (array_slice($argv, 2) as $k) { $v = is_array($v) && isset($v[$k]) ? $v[$k] : ""; } echo is_scalar($v) ? $v : "";' "$CFG" "$@" 2>/dev/null || true; }
CALL=$(php_val station callsign | tr -d '[:space:]' | tr '[:lower:]' '[:upper:]')
LOGIN=$(php_val bbs_mail user | tr -d '[:space:]')
[[ -n "$LOGIN" ]] || LOGIN=$(echo "${CALL%%-*}" | tr '[:upper:]' '[:lower:]')
[[ "$LOGIN" =~ ^[a-z0-9][a-z0-9._-]{1,30}$ ]] || die "set station.callsign in config.php first (mail login '$LOGIN' isn't usable)"

ours() { [[ -f "$DROPIN" ]] && grep -qF "$MARK" "$DROPIN"; }

# ── status ────────────────────────────────────────────────────────────────
if [[ $STATUS == y ]]; then
    echo; echo "${B}BBS mail store${N}"
    ours && ok "set up ($DROPIN)" || warn "not set up (run this script without --status)"
    systemctl is-active --quiet dovecot && ok "Dovecot running" || warn "Dovecot not running"
    systemctl is-active --quiet bbs-mail-sync.timer && ok "bbs-mail-sync.timer on (every 2 minutes)" || warn "bbs-mail-sync.timer off"
    if command -v ss >/dev/null; then
        ss -ltn | awk '{print $4}' | grep -E ':(993|143)$' | sed 's/^/  listening: /' || true
    fi
    [[ -f "$PASSWD" ]] && say "mail login: $(cut -d: -f1 "$PASSWD" | paste -sd, -)  (password set)"
    [[ -f "$SECRET" && -f "$MASTERPW" ]] && say "Email Client → Mail store: login set (this computer only)" \
        || warn "Email Client → Mail store: no dashboard login yet (run this script again without --status)"
    echo; python3 "$SYNC" --status || true
    exit 0
fi

# ── remove ────────────────────────────────────────────────────────────────
if [[ $REMOVE == y ]]; then
    echo; echo "${B}Removing the BBS mail store settings${N} $([[ $DRY == y ]] && echo '(dry run)')"
    run systemctl disable --now bbs-mail-sync.timer 2>/dev/null || true
    for u in bbs-mail-sync.timer bbs-mail-sync.service; do
        [[ -f /etc/systemd/system/$u ]] && run rm -f /etc/systemd/system/$u
    done
    run systemctl daemon-reload
    if ours; then
        run rm -f "$DROPIN" "$PASSWD" "$MASTERPW" "$SECRET" "$HOOK"
        if [[ -f "$AUTHCONF" ]] && grep -q '^#!include auth-system.conf.ext *# BPQ Dashboard' "$AUTHCONF"; then
            run sed -i 's/^#!include auth-system.conf.ext *# BPQ Dashboard.*$/!include auth-system.conf.ext/' "$AUTHCONF"
        fi
        run systemctl stop dovecot 2>/dev/null || true
        run systemctl disable dovecot 2>/dev/null || true
        ok "Dovecot settings removed and Dovecot stopped (to uninstall it: sudo apt purge dovecot-core dovecot-imapd)"
    else
        say "no BPQ Dashboard Dovecot settings found"
    fi
    if command -v iptables >/dev/null; then
        while read -r rule; do
            [[ -n "$rule" ]] || continue
            eval "run iptables ${rule/-A /-D }"
        done < <(iptables -S INPUT 2>/dev/null | grep -F -- "$IPT_COMMENT" || true)
        command -v netfilter-persistent >/dev/null && run netfilter-persistent save >/dev/null 2>&1 || true
    fi
    if [[ $PURGE == y ]]; then
        run rm -rf "$STORE"
        run python3 - "$WEB_ROOT" <<'PY' || true
import sys; sys.path.insert(0, sys.argv[1] + '/scripts'); import bpq_db_config, pymysql
c = pymysql.connect(**bpq_db_config.load_db_config()); c.cursor().execute('DELETE FROM bpq_mail_sync'); c.commit()
PY
        ok "copied mail deleted ($STORE)"
    else
        say "the copied mail is kept in $STORE (add --purge to delete it)"
    fi
    exit 0
fi

# ── password only ─────────────────────────────────────────────────────────
set_password() {
    [[ -t 0 ]] || die "the password is typed at a terminal: run this in a terminal (ssh or the desktop's)"
    local uid gid hash tmp
    uid=$(id -u "$MUSER"); gid=$(id -g "$MUSER")
    echo
    echo "  ${B}Choose the mail password for login '$LOGIN'${N} (typed twice, not shown)."
    echo "  Use a NEW password: not your BBS, sysop or computer password. 12+ characters."
    hash=$(doveadm pw -s SHA512-CRYPT) || die "password not set"
    [[ "$hash" == '{SHA512-CRYPT}'* ]] || die "password not set"
    tmp=$(mktemp "$PASSWD.XXXX")
    # user:password:uid:gid:(gecos):home:(shell):extra fields
    printf '%s:%s:%s:%s::%s/%s::allow_nets=%s\n' "$LOGIN" "$hash" "$uid" "$gid" "$STORE" "$LOGIN" "$LAN_NETS" > "$tmp"
    chown root:dovecot "$tmp"; chmod 640 "$tmp"; mv "$tmp" "$PASSWD"
    unset hash
    ok "mail password saved (hashed) in $PASSWD"
}
if [[ $PWONLY == y ]]; then
    ours || die "not set up yet: run this script without --password"
    set_password
    systemctl reload dovecot 2>/dev/null || true
    exit 0
fi

# ── set up ────────────────────────────────────────────────────────────────
echo; echo "${B}BPQ Dashboard — BBS mail in your mail app (IMAP)${N} $([[ $DRY == y ]] && echo '(dry run: nothing is changed)')"
say "mail login: $LOGIN    station: ${CALL:-?}"

# Dovecot already used for something else? Don't take it over.
if [[ -d /etc/dovecot ]] && ! ours && [[ $FORCE != y ]]; then
    if dpkg -s dovecot-core >/dev/null 2>&1 && \
       { [[ -f /etc/dovecot/local.conf ]] || ls /etc/dovecot/conf.d/*.conf 2>/dev/null | grep -qv -E '/(10|15|20|90)-[a-z0-9-]+\.conf$'; }; then
        die "Dovecot is already installed and has settings this script didn't write.
         It won't take over a mail server you use for something else.
         If this computer's Dovecot isn't used, run again with --force."
    fi
fi

# Certificate
CERT=""; KEY=""
if [[ -z "$DOMAIN" && -d /etc/letsencrypt/live ]]; then
    mapfile -t DOMS < <(find /etc/letsencrypt/live -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort)
    if (( ${#DOMS[@]} == 1 )); then
        DOMAIN="${DOMS[0]}"
    elif (( ${#DOMS[@]} > 1 )); then
        if [[ -f "$DROPIN" ]] && grep -q '^ssl_cert = </etc/letsencrypt/live/' "$DROPIN"; then
            DOMAIN=$(sed -n 's|^ssl_cert = </etc/letsencrypt/live/\([^/]*\)/.*|\1|p' "$DROPIN")
        elif [[ -t 0 ]]; then
            echo; say "You have several Let's Encrypt certificates. Mail apps connect to the name you choose:"
            for i in "${!DOMS[@]}"; do say "  $((i+1))) ${DOMS[$i]}"; done
            read -r -p "  Which one [1]: " a; a=${a:-1}
            [[ "$a" =~ ^[0-9]+$ ]] && (( a >= 1 && a <= ${#DOMS[@]} )) || die "no such choice"
            DOMAIN="${DOMS[$((a-1))]}"
        else
            die "several certificates: choose one with --domain (${DOMS[*]})"
        fi
    fi
fi
if [[ -n "$DOMAIN" ]]; then
    CERT=/etc/letsencrypt/live/$DOMAIN/fullchain.pem; KEY=/etc/letsencrypt/live/$DOMAIN/privkey.pem
    [[ -f "$CERT" && -f "$KEY" ]] || die "no certificate for $DOMAIN in /etc/letsencrypt/live"
    ok "certificate: $DOMAIN (Let's Encrypt)"
else
    warn "no Let's Encrypt certificate: Dovecot's own is used (mail apps ask you to accept it once)"
fi

# 1. Dovecot
if ! dpkg -s dovecot-imapd >/dev/null 2>&1; then
    say "installing Dovecot (dovecot-imapd)…"
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y -q dovecot-imapd >/dev/null
fi
[[ $DRY == y ]] || command -v doveadm >/dev/null || die "Dovecot didn't install"
ok "Dovecot installed$([[ $DRY == y ]] || echo " ($(dovecot --version | cut -d' ' -f1))")"

# 2. mail owner
if ! id "$MUSER" >/dev/null 2>&1; then
    run useradd --system --home-dir "$STORE" --no-create-home --shell /usr/sbin/nologin --user-group "$MUSER"
fi
run install -d -o "$MUSER" -g "$MUSER" -m 750 "$STORE"
ok "mail owner $MUSER, mail in $STORE"
UIDN=$(id -u "$MUSER" 2>/dev/null || echo 999)
# IPv6 switched off on some stations: Dovecot stops if it can't listen on ::
LISTEN="*, ::"; [[ -s /proc/net/if_inet6 ]] || LISTEN="*"

# 3. settings
DROPIN_TEXT="$MARK (tools/bbs-mail-setup.sh, $(date +%F)).
# Re-written by the script; to change it, run the script again. Remove: --remove
# Read-only copy of BBS mail: IMAP only, no outgoing mail, nothing on port 25.

protocols = imap
listen = $LISTEN

# Only the mail login in $PASSWD; this computer's own accounts can't log in.
disable_plaintext_auth = yes
auth_mechanisms = plain login
passdb {
  driver = passwd-file
  args = scheme=SHA512-CRYPT username_format=%Ln $PASSWD
}
userdb {
  driver = passwd-file
  args = username_format=%Ln $PASSWD
}
# The dashboard's own login (Email Client -> Mail store): "$LOGIN*bpqdash",
# from this computer only (127.0.0.1); its password is in $SECRET.
auth_master_user_separator = *
passdb {
  driver = passwd-file
  master = yes
  pass = yes
  args = scheme=SHA512-CRYPT $MASTERPW
}
first_valid_uid = $UIDN
last_valid_uid = $UIDN
auth_failure_delay = 4 secs

# Folders: INBOX, Sent, Bulletins/WX ...
mail_location = maildir:$STORE/%Ln/Maildir:LAYOUT=fs
namespace inbox {
  separator = /
  mailbox Sent {
    auto = subscribe
  }
  mailbox Trash {
    auto = subscribe
  }
}

# Encrypted only. 143 (plain IMAP) answers this computer only, for the
# dashboard; mail apps use 993.
ssl = required
ssl_min_protocol = TLSv1.2
$( [[ -n "$CERT" ]] && printf 'ssl_cert = <%s\nssl_key = <%s\n' "$CERT" "$KEY" || true )
$( dpkg -s dovecot-pop3d >/dev/null 2>&1 && printf '%s\n' '# dovecot-pop3d is installed: keep it off 110/995 (LinBPQ has its own POP3 on 110)' 'service pop3-login {' '  inet_listener pop3 {' '    port = 0' '  }' '  inet_listener pop3s {' '    port = 0' '  }' '}' || true )
service imap-login {
  inet_listener imap {
    address = 127.0.0.1
    port = 143
  }
  inet_listener imaps {
    port = 993
    ssl = yes
  }
}
login_greeting = BBS mail ready.
"
if [[ $DRY == y ]]; then
    say "would write $DROPIN:"; echo "$DROPIN_TEXT" | sed 's/^/      /'
else
    [[ -f "$DROPIN" ]] && ! ours && cp -p "$DROPIN" "/root/99-bpq-dashboard.conf.bak-$(date +%Y%m%d%H%M%S)"
    printf '%s' "$DROPIN_TEXT" > "$DROPIN"; chmod 644 "$DROPIN"
fi
if [[ -f "$AUTHCONF" ]] && grep -q '^!include auth-system.conf.ext' "$AUTHCONF"; then
    run cp -p "$AUTHCONF" "/root/10-auth.conf.bak-$(date +%Y%m%d%H%M%S)"
    run sed -i 's/^!include auth-system.conf.ext.*$/#!include auth-system.conf.ext  # BPQ Dashboard: computer accounts off for mail/' "$AUTHCONF"
fi
# A protocols line in dovecot.conf or another conf.d file (an older hand
# edit) can win over the drop-in: Dovecot then starts POP3 too (port 110 is
# LinBPQ's). Comment such lines out (backups in /root).
for f in /etc/dovecot/dovecot.conf /etc/dovecot/conf.d/*.conf /etc/dovecot/local.conf; do
    [[ -f "$f" && "$f" != "$DROPIN" ]] || continue
    grep -qE '^\s*protocols\s*=' "$f" || continue
    run cp -p "$f" "/root/$(basename "$f").bak-$(date +%Y%m%d%H%M%S)"
    run sed -i -E 's/^(\s*protocols\s*=.*)$/#\1  # BPQ Dashboard: set in conf.d\/99-bpq-dashboard.conf/' "$f"
    say "$f had its own protocols line: commented out (backup in /root)"
done
ok "settings: $DROPIN (logins with this computer's accounts turned off)"

# 4. password
if [[ $DRY == y ]]; then
    [[ -f "$PASSWD" ]] && say "mail password already set (keep it; --password to change)" || say "would ask for the mail password"
elif [[ -f "$PASSWD" ]] && grep -q "^$LOGIN:{SHA512-CRYPT}" "$PASSWD"; then
    ok "mail password already set (to change it: --password)"
else
    set_password
fi

# 4b. the dashboard's own login to the mail store (Email Client -> Mail store)
WEB_GROUP=$(stat -c %G "$CFG" 2>/dev/null || echo www-data)
if [[ $DRY == y ]]; then
    [[ -f "$SECRET" && -f "$MASTERPW" ]] && say "dashboard login to the mail store already set" \
        || say "would create the dashboard's own login (from this computer only) in $MASTERPW and $SECRET"
elif [[ -f "$SECRET" && -f "$MASTERPW" ]] && grep -q '^bpqdash:{SHA512-CRYPT}' "$MASTERPW"; then
    ok "dashboard login to the mail store already set"
else
    command -v openssl >/dev/null || die "openssl is needed"
    mpw=$(openssl rand -base64 33 | tr -d '/+=\n' | cut -c1-32)
    mhash=$(printf '%s' "$mpw" | openssl passwd -6 -stdin)
    tmp=$(mktemp "$MASTERPW.XXXX")
    printf 'bpqdash:{SHA512-CRYPT}%s::::::allow_nets=127.0.0.1/32,::1/128\n' "$mhash" > "$tmp"
    chown root:dovecot "$tmp"; chmod 640 "$tmp"; mv "$tmp" "$MASTERPW"
    install -d -o root -g root -m 755 "$SECRET_DIR"
    tmp=$(mktemp "$SECRET.XXXX")
    printf "<?php\n// BPQ Dashboard: the Email Client's login to the BBS mail store (tools/bbs-mail-setup.sh).\n// Works only from this computer. Re-created by the setup script; don't copy it elsewhere.\nreturn ['host' => '127.0.0.1', 'port' => 143, 'user' => '%s', 'master' => 'bpqdash', 'pass' => '%s'];\n" "$LOGIN" "$mpw" > "$tmp"
    chown root:"$WEB_GROUP" "$tmp"; chmod 640 "$tmp"; mv "$tmp" "$SECRET"
    unset mpw mhash
    ok "dashboard login to the mail store created (from this computer only; $SECRET, readable by $WEB_GROUP)"
fi

# 5. certificate renewals reload Dovecot
if [[ -n "$CERT" && -d /etc/letsencrypt/renewal-hooks/deploy ]]; then
    if [[ $DRY == y ]]; then say "would write $HOOK"; else
        printf '#!/bin/sh\n# BPQ Dashboard: Dovecot picks up a renewed certificate (tools/bbs-mail-setup.sh)\nsystemctl reload dovecot >/dev/null 2>&1 || true\n' > "$HOOK"
        chmod 755 "$HOOK"
    fi
    ok "certificate renewals reload Dovecot"
fi

if [[ $DRY != y ]]; then
    doveconf -n >/dev/null 2>/tmp/doveconf.err || { cat /tmp/doveconf.err >&2; die "Dovecot doesn't accept the settings (above)"; }
    rm -f /tmp/doveconf.err
    systemctl enable dovecot >/dev/null 2>&1 || true
    systemctl restart dovecot || die "Dovecot didn't start: sudo journalctl -u dovecot -n 30"
fi
ok "Dovecot running: IMAP over SSL on port 993 (your network only)"

# Firewall: when ufw is on, let your own network reach 993
if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q '^Status: active'; then
    for net in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16; do
        ufw status | grep -qE "993/tcp.*ALLOW.*$net" || run ufw allow from "$net" to any port 993 proto tcp comment 'BPQ Dashboard BBS mail (LAN)' >/dev/null
    done
    ok "firewall (ufw): port 993 open to your own network"
elif command -v iptables >/dev/null && iptables -S INPUT 2>/dev/null | grep -qE '^-P INPUT (DROP|REJECT)'; then
    # iptables with a closed INPUT policy (K1AJD's station): open 993 to the
    # station's own network only, and save it.
    LANNET=$(ip -4 route show scope link 2>/dev/null | awk '$1 ~ /^(10|172|192)\./ && $1 ~ /\// {print $1; exit}')
    if [[ -z "$LANNET" ]]; then
        warn "firewall: couldn't tell your network's range; open 993 to it yourself, e.g.: sudo iptables -I INPUT -p tcp -s 192.168.1.0/24 --dport 993 -j ACCEPT"
    elif iptables -S INPUT | grep -F -- "$IPT_COMMENT" | grep -qF -- "$LANNET"; then
        ok "firewall (iptables): port 993 already open to $LANNET"
    else
        run iptables -I INPUT -p tcp -s "$LANNET" --dport 993 -m comment --comment "$IPT_COMMENT" -j ACCEPT
        if command -v netfilter-persistent >/dev/null; then run netfilter-persistent save >/dev/null 2>&1 || true
        else warn "netfilter-persistent not installed: the 993 rule is lost at reboot (sudo apt install iptables-persistent)"; fi
        ok "firewall (iptables): port 993 open to $LANNET (your network), saved"
    fi
fi

# 6. database table + copier
run python3 "$SYNC" --status >/dev/null 2>&1 || warn "the bpq_mail_sync table is missing: run update.sh once (it adds it)"
for u in bbs-mail-sync.service bbs-mail-sync.timer; do
    if [[ $DRY == y ]]; then say "would install /etc/systemd/system/$u"; else
        sed "s|/var/www/bpq-dashboard|$WEB_ROOT|g" "$WEB_ROOT/$u" > "/etc/systemd/system/$u"
    fi
done
run systemctl daemon-reload
if [[ $DRY != y ]]; then
    say "copying the BBS mail the dashboard has saved (the first time can take a few minutes)…"
    python3 "$SYNC" 2>&1 | tail -3 | sed 's/^/    /' || warn "first copy reported a problem (see above)"
fi
run systemctl enable --now bbs-mail-sync.timer >/dev/null 2>&1
ok "bbs-mail-sync.timer on: new BBS mail is copied every 2 minutes"

HOSTN=${DOMAIN:-$(hostname -I 2>/dev/null | awk '{print $1}')}
echo
echo "${B}Mail app settings${N} (Thunderbird: Account Settings > Add Mail Account > Configure manually)"
say "Incoming: IMAP   server $HOSTN   port 993   SSL/TLS   Normal password"
say "Username: $LOGIN    Password: the mail password you just chose"
say "Outgoing: none needed (this is a read-only copy; reply on the BBS)"
[[ -n "$DOMAIN" ]] && say "${D}If $DOMAIN doesn't reach this computer from home, ask: the name must match the certificate.${N}"
say "${D}Check any time: sudo bash $0 --status${N}"
