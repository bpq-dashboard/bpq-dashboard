<?php
/**
 * system-audit-api.php — System health audit API
 * Version: 2.0.0 (2026-10-25)
 *
 * Checks the server and the dashboard's own services and returns JSON
 * findings: status ok / warn / crit, or info (not checked / not installed;
 * info rows don't count towards the score).
 *
 * 2026-10-25 rewrite — every check now describes this install, not one station:
 *  - Paths come from the web root this file lives in (they were the literal
 *    text "/var/www/<dashboard>", so cache, nav, chat, APRS and WaveNode rows
 *    were wrong on every install).
 *  - sudo commands are exactly the ones in www-data-tools.sudoers, run with
 *    sudo -n; a refused sudo is reported as "can't check", not as a fault.
 *  - Removed: WaveNode (hardware retired), PAT Winlink, nws-monitor, Webmin,
 *    Conky, fwupd, xinetd and the PHP "EOL" list (distros patch their PHP).
 *  - Added: IPv6 firewall, BPQ/MariaDB ports open to the internet, the
 *    admin-login and private-file tests, CORS, config file modes, database
 *    tables and migrations, the log parsers, message-bodies and
 *    bulletin-retention timers, nws-auto-post.timer (when switched on in
 *    config.php), every dashboard daemon (installed or not),
 *    APRS-IS / beacon state, flrig, QRZ lookups and the nav cache-bust.
 *
 * Needs /etc/sudoers.d/www-data-tools (copy of www-data-tools.sudoers) for
 * the firewall, fail2ban, ss -p and nginx -t rows. Without it those rows say
 * what is missing; everything else works without sudo.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Auth ──────────────────────────────────────────────────────────────────────
// /admin/ is behind the admin login, and the PHP checks that login itself.
require_once dirname(__DIR__) . '/includes/web-login.php';
bpq_require_web_login('System Audit');
$cfg = bpq_config_all();

$WEB_ROOT  = rtrim($cfg['paths']['web_root'] ?? dirname(__DIR__), '/');
$LOG_DIR   = rtrim($cfg['paths']['logs'] ?? ($WEB_ROOT . '/logs'), '/');
$CACHE_DIR = $WEB_ROOT . '/cache';
$SUDOERS_HINT = "Install the sudo rules: sudo cp $WEB_ROOT/www-data-tools.sudoers /etc/sudoers.d/www-data-tools && sudo chmod 440 /etc/sudoers.d/www-data-tools && sudo visudo -c";

// ── Helpers ───────────────────────────────────────────────────────────────────
function sh(string $cmd): string {
    return trim((string)shell_exec($cmd . ' 2>&1'));
}
function sh_quiet(string $cmd): string {
    return trim((string)shell_exec($cmd . ' 2>/dev/null'));
}
/** sudo -n never prompts; returns [ok, output]. ok=false when sudo refused. */
function sudo_run(string $cmd): array {
    $out = sh('sudo -n ' . $cmd);
    $refused = preg_match('/^sudo: |a password is required|not allowed to execute|may not run sudo/m', $out);
    return [!$refused, $out];
}
function finding(string $id, string $category, string $status, string $title,
                 string $detail, string $recommendation = ''): array {
    return compact('id','category','status','title','detail','recommendation');
}
/**
 * v1.5.93: a USB serial port that came back after flrig started.
 * Returns null when nothing is wrong (or flrig isn't running), else
 * ['sure' => bool, 'port', 'dev', 'dev_at', 'started']. sure = the port is the
 * one flrig's settings name (~/.flrig/*.prefs xcvr_serial_port of the rig in
 * use); otherwise any /dev/serial/by-id device newer than flrig.
 */
function flrig_stale_port(): ?array {
    $pid = (int)trim(sh_quiet('pgrep -xo flrig'));
    if ($pid <= 0) return null;
    $age = (int)trim(sh_quiet('ps -o etimes= -p ' . $pid));
    if ($age <= 0) return null;
    $start = time() - $age;
    $fmt = fn($t) => gmdate('Y-m-d H:i', $t) . 'Z';
    $newer = function (string $port) use ($start) {
        $dev = @realpath($port);
        if (!$dev || strpos($dev, '/dev/') !== 0) return null;
        clearstatcache(true, $dev);
        $ct = @filectime($dev);
        return ($ct && $ct > $start + 60) ? [$dev, $ct] : null;
    };
    // flrig's settings: the rig in use (flrig.prefs) and its serial port
    $uid = null;
    foreach (@file("/proc/$pid/status") ?: [] as $l) if (preg_match('/^Uid:\s+(\d+)/', $l, $m)) { $uid = (int)$m[1]; break; }
    $home = ($uid !== null && function_exists('posix_getpwuid')) ? (posix_getpwuid($uid)['dir'] ?? null) : null;
    $ports = [];
    if ($home && @is_readable("$home/.flrig")) {
        $active = '';
        if (preg_match('/^xcvr_name:(.+)$/m', (string)@file_get_contents("$home/.flrig/flrig.prefs"), $m)) $active = strtoupper(trim($m[1]));
        foreach (glob("$home/.flrig/*.prefs") ?: [] as $f) {
            $t = (string)@file_get_contents($f);
            if (!preg_match('/^xcvr_serial_port:(.+)$/m', $t, $m) || strtoupper(trim($m[1])) === 'NONE') continue;
            $name = preg_match('/^xcvr_name:(.+)$/m', $t, $n) ? strtoupper(trim($n[1])) : '';
            $ports[trim($m[1])] = ($active === '' || $name === '' || $name === $active);
        }
        $use = array_keys(array_filter($ports)) ?: array_keys($ports);
        foreach ($use as $p) {
            if ($r = $newer($p)) return ['sure' => true, 'port' => $p, 'dev' => $r[0], 'dev_at' => $fmt($r[1]), 'started' => $fmt($start)];
        }
        if ($use) return null;                          // its port is older than flrig: fine
    }
    foreach (glob('/dev/serial/by-id/*') ?: [] as $p) {
        if ($r = $newer($p)) return ['sure' => false, 'port' => basename($p), 'dev' => $r[0], 'dev_at' => $fmt($r[1]), 'started' => $fmt($start)];
    }
    return null;
}

/**
 * v1.5.93: radio modems started before a USB sound card or USB serial port
 * last (re)appeared. Each entry: id, name, started, dev, dev_at, fix.
 */
function stale_modems(): array {
    // USB devices and when their nodes appeared
    $devs = [];
    foreach (glob('/proc/asound/card[0-9]*') ?: [] as $c) {
        if (!file_exists("$c/usbid")) continue;                 // USB sound cards only
        $n = substr(basename($c), 4);
        $name = trim((string)@file_get_contents("$c/id")) ?: "card $n";
        foreach (glob("/dev/snd/pcmC{$n}D*") ?: [] as $p) {
            clearstatcache(true, $p);
            if ($t = @filectime($p)) $devs[] = ["sound card $name", $t];
        }
    }
    foreach (glob('/dev/serial/by-id/*') ?: [] as $l) {
        $r = @realpath($l);
        if ($r) { clearstatcache(true, $r); if ($t = @filectime($r)) $devs[] = [basename($r) . ' (' . preg_replace('/^usb-|-if\d+.*$/', '', basename($l)) . ')', $t]; }
    }
    if (!$devs) return [];
    usort($devs, fn($a, $b) => $b[1] <=> $a[1]);
    $fmt = fn($t) => gmdate('Y-m-d H:i', $t) . 'Z';
    $mods = [
        ['data2g', 'Data2G',       "pgrep -o -f '[d]ata2g-web'",       'sudo systemctl restart data2g, then ss -ltnp | grep 8500 should show it listening'],
        ['mercury', 'Mercury',     'pgrep -o -x mercury',              'sudo systemctl restart mercury'],
        ['qtsm', 'QtSoundModem',   "pgrep -o -f '[Q]tSoundModem'",     'sudo systemctl restart qtsoundmodem'],
        ['vara', 'VARA HF',        "pgrep -o -f '[V]ARA.exe'",         'Close and reopen VARA HF on the desktop'],
    ];
    $out = [];
    foreach ($mods as [$id, $name, $cmd, $fix]) {
        $pid = (int)trim(sh_quiet($cmd));
        if ($pid <= 0) continue;
        $age = (int)trim(sh_quiet("ps -o etimes= -p $pid"));
        if ($age <= 0) continue;
        $start = time() - $age;
        foreach ($devs as [$dname, $t]) {
            if ($t <= $start + 60) break;                       // newest first: nothing newer than this modem
            $out[] = ['id' => $id, 'name' => $name, 'started' => $fmt($start), 'dev' => $dname, 'dev_at' => $fmt($t), 'fix' => $fix];
            break;
        }
    }
    return $out;
}

function unit_state(string $unit): array {
    // [installed(bool), active-state, enabled-state]  — no sudo needed
    $load = sh_quiet('systemctl show ' . escapeshellarg($unit) . ' -p LoadState --value');
    if ($load !== 'loaded') return [false, 'not-installed', ''];
    return [true,
        sh_quiet('systemctl is-active ' . escapeshellarg($unit)),
        sh_quiet('systemctl is-enabled ' . escapeshellarg($unit))];
}
function age_str(int $s): string {
    if ($s < 120)   return "{$s}s";
    if ($s < 7200)  return round($s / 60) . ' min';
    if ($s < 172800) return round($s / 3600, 1) . ' h';
    return round($s / 86400) . ' days';
}
function json_file(string $p): ?array {
    if (!is_readable($p)) return null;
    $d = json_decode((string)@file_get_contents($p), true);
    return is_array($d) ? $d : null;
}

$findings = [];

// ── Listening sockets (ss -tln needs no sudo; -p does) ────────────────────────
$listen = [];   // port => list of bind addresses
foreach (explode("\n", sh_quiet('ss -Htln')) as $l) {
    $p = preg_split('/\s+/', trim($l));
    if (count($p) < 4) continue;
    if (preg_match('/^(.*):(\d+)$/', $p[3], $m)) $listen[(int)$m[2]][] = trim($m[1], '[]');
}
function is_public_bind(array $addrs): bool {
    foreach ($addrs as $a) {
        $a = preg_replace('/%.*$/', '', $a);
        if (in_array($a, ['0.0.0.0', '::', '*'], true)) return true;
        if (!preg_match('/^(127\.|::1$|::ffff:127\.)/', $a)) return true;  // bound to a LAN/WAN address
    }
    return false;
}

// ── Firewall (shared by several checks) ───────────────────────────────────────
require_once dirname(__DIR__) . '/includes/admin-firewall.php';
[$fw4ok, $fw4] = sudo_run('/usr/sbin/iptables -L -n --line-numbers -v');
$fw4chains = $fw4ok ? bpq_fw_parse_all($fw4) : [];
$fw4policy = $fw4chains['INPUT']['policy'] ?? null;

// ═══════════════════════════════════════════════════════
// 1. SECURITY
// ═══════════════════════════════════════════════════════

// Admin login — this request only got here because the login was checked.
$loginUser = bpq_web_login_user();
if (($cfg['require_web_login'] ?? true) === false) {
    $findings[] = finding('admin-login','security','warn','PHP login check is switched off',
        "config.php has 'require_web_login' => false, so the PHP files that act as the station trust any request that reaches them. Only the web server's own rules protect them.",
        "Remove 'require_web_login' => false from config.php (the default is on).");
} else {
    $findings[] = finding('admin-login','security','ok','Admin login reaches PHP',
        'This audit was opened through the web server login' . ($loginUser !== '' ? " (user $loginUser)" : '') . ', and the PHP check is on.');
}

// Login file (nginx / Apache) — installer default /etc/nginx/.bpq-mgmt-auth
$authFile = '/etc/nginx/.bpq-mgmt-auth';
if (file_exists($authFile)) {
    $mode = fileperms($authFile) & 0777;
    $owner = sh_quiet("stat -c '%U:%G' " . escapeshellarg($authFile));
    if ($mode & 0004) {
        $findings[] = finding('admin-auth-perms','security','warn','Admin login file is world-readable (' . decoct($mode) . ')',
            "$authFile ($owner) can be read by every account on the server, password hashes included.",
            "sudo chown root:www-data $authFile && sudo chmod 640 $authFile");
    } elseif (!is_readable($authFile)) {
        // PHP runs as the web user, so this is what nginx's workers see too
        // (2026-10-30, KG9NI: root:root left every admin page at 500).
        $findings[] = finding('admin-auth-perms','security','crit','Web server cannot read the admin login file',
            "$authFile is " . decoct($mode) . " $owner; the web user can't read it, so pages using it answer 500. (This page opened, so your site probably uses another file.)",
            "sudo chown root:www-data $authFile && sudo chmod 640 $authFile");
    } else {
        $findings[] = finding('admin-auth-perms','security','ok','Admin login file protected',
            "$authFile: mode " . decoct($mode) . " $owner.");
    }
} else {
    $findings[] = finding('admin-auth-perms','security','info','Admin login file not at the installer location',
        "$authFile doesn't exist. The login still works (this page opened), so your web server keeps it elsewhere; check that file isn't world-readable.");
}

// Private files and admin pages must not open without the login — ask the
// web server the way a visitor would (loopback, no credentials).
$host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+$/', $host) && trim(sh_quiet('command -v curl')) !== '') {
    $port = $https ? 443 : 80;
    $base = ($https ? 'https' : 'http') . "://$host";
    $mustLogin = ['/admin/system-audit-api.php', '/install-check.php', '/health-check.php'];
    $mustDeny  = ['/config.php', '/db-config.php', '/data/partners.json', '/includes/bootstrap.php'];
    $open = []; $leak = []; $tested = 0;
    foreach (array_merge($mustLogin, $mustDeny) as $path) {
        if (in_array($path, $mustDeny, true) && !file_exists($WEB_ROOT . $path)) continue;
        $code = sh_quiet('curl -s -o /dev/null -m 5 -w "%{http_code}" -A "Mozilla/5.0 (system-audit)" --resolve '
            . escapeshellarg("$host:$port:127.0.0.1") . ' ' . escapeshellarg($base . $path));
        if ($code === '' || $code === '000') continue;
        $tested++;
        if ($code === '200') {
            if (in_array($path, $mustLogin, true)) $open[] = $path; else $leak[] = $path;
        }
    }
    if ($tested === 0) {
        $findings[] = finding('private-urls','security','info','Login test not run',
            "The server couldn't reach itself at $base (curl via 127.0.0.1). Run the curl tests on the Maintenance page from another machine.");
    } elseif ($open) {
        $findings[] = finding('private-urls','security','crit','Admin files open without the login',
            "These answered 200 to a request with no login: " . implode(', ', $open) . ". Anyone can open them.",
            "Put the admin login on /admin/, install-check.php and health-check.php (see the LOGIN section of nginx-bpq-dashboard.conf), then sudo nginx -t && sudo systemctl reload nginx.");
    } elseif ($leak) {
        $findings[] = finding('private-urls','security','warn','Private files reachable from the web',
            "These answered 200: " . implode(', ', $leak) . ". PHP config files print nothing, but they should still be refused (403).",
            "Add the private-file rules from nginx-bpq-dashboard.conf (location blocks returning 403) and reload nginx.");
    } else {
        $findings[] = finding('private-urls','security','ok','Admin and private files refused without the login',
            "Tested $tested address(es) on $host with no login: none answered 200.");
    }
}

// Credential files must not be world-readable
$loose = [];
foreach (['config.php','db-config.php','bbs-config.php','nws-config.php','tprfn-config.php','bpqdash-config.php'] as $f) {
    $p = "$WEB_ROOT/$f";
    if (file_exists($p) && (fileperms($p) & 0004)) $loose[] = "$f (" . decoct(fileperms($p) & 0777) . ')';
}
$findings[] = $loose
    ? finding('config-perms','security','warn','Config files with passwords are world-readable',
        implode(', ', $loose) . ' can be read by every account on the server.',
        "sudo WEB_ROOT=$WEB_ROOT bash $WEB_ROOT/fix-permissions.sh   (sets them to 640)")
    : finding('config-perms','security','ok','Config files are not world-readable',
        'config.php and the other credential files present are 640 or tighter.');

// CORS
if (($cfg['cors']['allow_all'] ?? false) === true) {
    $findings[] = finding('cors','security','warn','CORS allows every web site',
        "config.php 'cors' => ['allow_all' => true] lets any web site call the dashboard's endpoints (callsign lookups, proxies, BBS logins) from its visitors' browsers.",
        "Set 'allow_all' => false and list only the sites that need it in 'allowed_origins'.");
} else {
    $n = count((array)($cfg['cors']['allowed_origins'] ?? []));
    $findings[] = finding('cors','security','ok','CORS is same-origin',
        $n ? "Cross-site calls allowed only from $n listed origin(s)." : 'No other web site can call the dashboard\'s endpoints from a browser.');
}

// IPv4 firewall policy
if ($fw4policy === null) {
    $findings[] = finding('fw-policy','security','info','IPv4 firewall not checked',
        'www-data may not run "iptables -L -n --line-numbers -v" through sudo, so the policy is unknown.',
        $SUDOERS_HINT);
} elseif (in_array($fw4policy, ['DROP', 'REJECT'], true)) {
    $findings[] = finding('fw-policy','security','ok',"IPv4 firewall policy is $fw4policy",
        'Traffic that no rule allows is refused.' . (bpq_fw_is_ufw($fw4chains) ? ' Rules are managed by ufw (sudo ufw status verbose).' : ''));
} else {
    $findings[] = finding('fw-policy','security','warn',"IPv4 firewall policy is $fw4policy",
        'Every port that a program listens on is reachable unless a rule blocks it.',
        'Allow what you need (SSH, 80, 443), then: sudo iptables -P INPUT DROP && sudo netfilter-persistent save (or use ufw: sudo ufw default deny incoming && sudo ufw enable).');
}

// IPv6 firewall — only matters when the server has a global IPv6 address
$globalV6 = false;
foreach (@file('/proc/net/if_inet6', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    $p = preg_split('/\s+/', trim($l));
    if (count($p) >= 6 && $p[3] === '00' && $p[5] !== 'lo' && !preg_match('/^f[cd]/', $p[0])) { $globalV6 = true; break; }
}
if (!$globalV6) {
    $findings[] = finding('fw6-policy','security','ok','No public IPv6 address',
        'The server has no global IPv6 address, so the IPv6 firewall doesn\'t matter here.');
} else {
    [$ok6, $fw6] = sudo_run('/usr/sbin/ip6tables -L -n --line-numbers -v');
    $p6 = ($ok6 && preg_match('/^Chain INPUT \(policy (\w+)/m', $fw6, $m)) ? $m[1] : null;
    if ($p6 === null) {
        $findings[] = finding('fw6-policy','security','info','IPv6 firewall not checked',
            'The server has a public IPv6 address, but www-data may not run "ip6tables -L -n --line-numbers -v" through sudo.',
            $SUDOERS_HINT . '   Or check by hand: sudo ip6tables -S INPUT | head -3');
    } elseif (in_array($p6, ['DROP', 'REJECT'], true)) {
        $findings[] = finding('fw6-policy','security','ok',"IPv6 firewall policy is $p6",
            'The server has a public IPv6 address and its firewall refuses unmatched traffic.');
    } else {
        $findings[] = finding('fw6-policy','security','crit',"IPv6 firewall policy is $p6",
            'The server has a public IPv6 address and IPv6 traffic is accepted by default: everything that listens on all interfaces is reachable from the internet over IPv6, whatever the IPv4 rules say.',
            'See SECURITY.md step 4: mirror your IPv4 rules in ip6tables and set sudo ip6tables -P INPUT DROP (ufw does both when IPV6=yes in /etc/default/ufw).');
    }
}

// Ports that should never be open to the internet
$bpqCfgText = '';
foreach (array_filter([!empty($cfg['paths']['linbpq']) ? rtrim($cfg['paths']['linbpq'], '/') . '/bpq32.cfg' : null,
                       '/opt/linbpq/bpq32.cfg', '/home/linbpq/bpq32.cfg', '/etc/linbpq/bpq32.cfg']) as $c) {
    if (is_readable($c)) { $bpqCfgText = (string)@file_get_contents($c); break; }
}
$sens = [
    (int)($cfg['bbs']['port'] ?? 8010) => 'BPQ telnet (passwords in clear text)',
    3306 => 'MariaDB', 23 => 'telnet', 8765 => 'bpq-telnet WebSocket', 8767 => 'bpq-vara WebSocket',
    (int)($cfg['radio']['flrig_port'] ?? 12345) => 'flrig (radio control)',
];
foreach (['HTTPPORT' => 'BPQ web management', 'TCPPORT' => 'BPQ telnet (passwords in clear text)', 'FBBPORT' => 'BPQ FBB port (passwords in clear text)'] as $k => $label) {
    if (preg_match('/^\s*' . $k . '\s*=\s*(\d+)/mi', $bpqCfgText, $m)) $sens[(int)$m[1]] = $label;
    elseif ($bpqCfgText === '') $sens += [($k === 'HTTPPORT' ? 8008 : ($k === 'FBBPORT' ? 8011 : 8010)) => $label];
}
$exposed = []; $shielded = [];
foreach ($sens as $port => $label) {
    if (empty($listen[$port]) || !is_public_bind($listen[$port])) continue;
    $pub = $fw4chains ? bpq_fw_port_public($fw4chains, $port) : null;
    if ($pub === false) $shielded[] = "$port $label"; else $exposed[] = "$port $label";
}
if ($exposed) {
    $findings[] = finding('exposed-ports','security', $fw4policy ? 'crit' : 'warn',
        ($fw4policy ? 'Station ports open to every network (check your router)' : 'Station ports listen on all interfaces'),
        'Listening on all interfaces' . ($fw4policy ? ' with no IPv4 firewall rule stopping them' : ' (firewall not readable, so they may be reachable)') . ': ' . implode('; ', $exposed) . '.'
        . ' Only ports your router forwards to this machine can be reached from the internet; System Audit cannot see the router.',
        'Bind them to 127.0.0.1 where the program allows it (MariaDB: bind-address = 127.0.0.1), or allow them only from your LAN in the firewall. BPQ telnet/FBB send passwords unencrypted; reach them from outside through the dashboard (HTTPS) or a VPN.');
} else {
    $findings[] = finding('exposed-ports','security','ok','Station ports not open to the internet',
        $shielded ? 'Listening on all interfaces but blocked by the firewall policy: ' . implode('; ', $shielded) . '.'
                  : 'BPQ, MariaDB, flrig and the dashboard WebSockets listen only on localhost (or not at all).');
}

// SSH + fail2ban
$f2bState = unit_state('fail2ban.service');
[$ssok, $ssOut] = sudo_run('/usr/bin/ss -tlnp');
$sshPorts = [];
if ($ssok) {
    // 2026-11-01: only ports reachable from the network. sshd also listens on
    // 127.0.0.1:6010+ while someone is logged in with ssh -X (X11 forwarding);
    // those are local-only and were reported as SSH ports (N3MEL).
    foreach (explode("\n", $ssOut) as $l) {
        if (strpos($l, '"sshd"') === false) continue;
        $p = preg_split('/\s+/', trim($l));
        if (count($p) >= 4 && preg_match('/^(.*):(\d+)$/', $p[3], $m) && is_public_bind([trim($m[1], '[]')])) $sshPorts[(int)$m[2]] = true;
    }
}
if (!$sshPorts && isset($listen[22])) $sshPorts[22] = true;   // best guess without -p
if (!$f2bState[0]) {
    $findings[] = finding('fail2ban','security', $sshPorts ? 'warn' : 'info','fail2ban not installed',
        'Nothing bans addresses that keep guessing SSH (or other) passwords.',
        'sudo apt install fail2ban   (the sshd jail is on by default)');
} elseif ($f2bState[1] !== 'active') {
    $findings[] = finding('fail2ban','security','crit','fail2ban is installed but not running',
        "fail2ban.service is {$f2bState[1]}.", 'sudo systemctl enable --now fail2ban');
} else {
    [$fok, $fout] = sudo_run('/usr/bin/fail2ban-client status');
    if ($fok && preg_match('/Jail list:\s*(.*)/i', $fout, $m)) {
        $jails = array_filter(array_map('trim', explode(',', $m[1])));
        $banned = 0;
        foreach ($jails as $j) {
            $j = preg_replace('/[^A-Za-z0-9_-]/', '', $j);
            [, $jo] = sudo_run('/usr/bin/fail2ban-client status ' . $j);
            if (preg_match('/Currently banned:\s*(\d+)/', $jo, $mm)) $banned += (int)$mm[1];
        }
        $findings[] = finding('fail2ban','security','ok','fail2ban running',
            count($jails) . ' jail(s): ' . implode(', ', $jails) . ". $banned address(es) banned right now.");
    } else {
        $findings[] = finding('fail2ban','security','ok','fail2ban running',
            'fail2ban.service is active. Jail details need the sudo rule for fail2ban-client.', $SUDOERS_HINT);
    }
}
if ($sshPorts) {
    $list = implode(', ', array_keys($sshPorts));
    $findings[] = finding('ssh-port','security', (isset($sshPorts[22]) && $f2bState[1] !== 'active') ? 'warn' : 'ok',
        "SSH listening on port $list",
        isset($sshPorts[22]) ? ($f2bState[1] === 'active' ? 'Standard port, protected by fail2ban.' : 'Standard port with no fail2ban: expect constant password guessing. Use keys only (PasswordAuthentication no) and install fail2ban.')
                             : 'Non-standard port: fewer automated login attempts.');
}

// ═══════════════════════════════════════════════════════
// 2. RESOURCES
// ═══════════════════════════════════════════════════════
$mem = [];
foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) $mem[$m[1]] = (int)$m[2];
}
$totalMB = round(($mem['MemTotal'] ?? 0) / 1024);
$availMB = round(($mem['MemAvailable'] ?? 0) / 1024);
$usedMB  = $totalMB - $availMB;
$usedPct = $totalMB > 0 ? round($usedMB / $totalMB * 100) : 0;
$swapTotal = round(($mem['SwapTotal'] ?? 0) / 1024);
$swapUsed  = $swapTotal - round(($mem['SwapFree'] ?? 0) / 1024);
$findings[] = finding('memory','resources', $usedPct > 90 ? 'crit' : ($usedPct > 80 ? 'warn' : 'ok'), 'Memory usage',
    "{$usedMB} MB used of {$totalMB} MB ({$usedPct}%). {$availMB} MB available.",
    $usedPct > 80 ? 'Biggest users: ps aux --sort=-%mem | head -10' : '');
$findings[] = finding('swap','resources', ($swapUsed > 500 && $usedPct > 80) ? 'warn' : 'ok', "Swap in use: {$swapUsed} MB",
    $swapTotal ? "{$swapUsed} MB of {$swapTotal} MB swap." : 'No swap configured.',
    ($swapUsed > 500 && $usedPct > 80) ? 'Memory is short: ps aux --sort=-%mem | head -10 shows what is using it.' : '');
$load = sys_getloadavg();
$cpuCount = max(1, (int)sh_quiet('nproc'));
$loadPct = round($load[0] / $cpuCount * 100);
$findings[] = finding('cpu','resources', $loadPct > 100 ? 'crit' : ($loadPct > 70 ? 'warn' : 'ok'), 'CPU load',
    'Load ' . implode(' / ', array_map(fn($l) => number_format($l, 2), $load)) . " on {$cpuCount} core(s) ({$loadPct}% of capacity).",
    $loadPct > 70 ? 'Biggest users: ps aux --sort=-%cpu | head -10' : '');
foreach (array_unique(['/', $WEB_ROOT]) as $mnt) {
    $free = @disk_free_space($mnt); $tot = @disk_total_space($mnt);
    if (!$tot) continue;
    $pct = round(($tot - $free) / $tot * 100);
    $dev = sh_quiet('df --output=target ' . escapeshellarg($mnt) . ' | tail -1');
    if ($mnt !== '/' && $dev === '/') continue;   // same filesystem as /
    $findings[] = finding('disk-' . md5($mnt),'resources', $pct > 90 ? 'crit' : ($pct > 80 ? 'warn' : 'ok'), "Disk space ($dev)",
        round(($tot - $free) / 1073741824, 1) . ' GB used of ' . round($tot / 1073741824, 1) . " GB ($pct%), " . round($free / 1073741824, 1) . ' GB free.',
        $pct > 80 ? "Usual growers: sudo du -sh /var/log /var/lib/mysql $LOG_DIR $WEB_ROOT/yapp-files 2>/dev/null" : '');
}

// ═══════════════════════════════════════════════════════
// 3. WEB SERVER & PHP
// ═══════════════════════════════════════════════════════
$software = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
$nginxUp  = unit_state('nginx.service')[1] === 'active';
$apacheUp = unit_state('apache2.service')[1] === 'active';
$isApache = strpos($software, 'apache') !== false || ($software === '' && $apacheUp && !$nginxUp);
$isNginx  = !$isApache && (strpos($software, 'nginx') !== false || $nginxUp);
if ($isApache) {
    $findings[] = finding('webserver','nginx','ok','Apache serves the dashboard', 'apache2 is running' . ($software ? " ($software)." : '.'));
} elseif (!$isNginx) {
    $findings[] = finding('webserver','nginx','info','Web server not identified', 'Neither nginx nor apache2 is running as a service here' . ($software ? " (this request: $software)." : '.'));
} else {
    $findings[] = finding('webserver','nginx','ok','nginx serves the dashboard', 'nginx is running' . ($nginxUp ? '' : ' (not as nginx.service)') . '.');
    [$tok, $tout] = sudo_run('/usr/sbin/nginx -t');
    if (!$tok) {
        $findings[] = finding('nginx-config','nginx','info','nginx configuration not tested',
            'www-data may not run "nginx -t" through sudo.', $SUDOERS_HINT . '   Or by hand: sudo nginx -t');
    } elseif (strpos($tout, 'test is successful') !== false) {
        $findings[] = finding('nginx-config','nginx','ok','nginx configuration is valid', 'nginx -t passed: the next reload will work.');
    } else {
        $findings[] = finding('nginx-config','nginx','crit','nginx configuration has errors',
            "nginx -t: $tout", 'Fix the file and line nginx -t names before reloading; until then a reload or restart takes every page down.');
    }
    // Recent errors in the error logs we can read (installer's file first)
    $errLogs = array_filter(['/var/log/nginx/bpq-dashboard.error.log', '/var/log/nginx/error.log'], 'is_readable');
    // 2026-11-01: 'bpq-protection-probe' is the Maintenance page's own check that
    // the admin pages are locked (a deliberately wrong login, four lines each time).
    $ignore = '/rename\(.*cache.*\.tmp|access forbidden by rule|limiting requests|bpq-protection-probe/';
    $errs = [];
    foreach ($errLogs as $el) {
        foreach (explode("\n", sh_quiet('tail -n 300 ' . escapeshellarg($el))) as $l) {
            if (preg_match('/\[(error|crit|alert|emerg)\]/', $l) && !preg_match($ignore, $l)) $errs[] = $l;
        }
    }
    if (!$errLogs) {
        $findings[] = finding('nginx-errors','nginx','info','nginx error log not readable',
            'www-data cannot read /var/log/nginx/*error.log here.', 'sudo tail -50 /var/log/nginx/error.log');
    } else {
        $n = count($errs);
        $findings[] = finding('nginx-errors','nginx', $n > 20 ? 'warn' : 'ok', $n ? "$n recent nginx error line(s)" : 'nginx error log is clean',
            ($n ? "Last: " . implode("\n", array_slice($errs, -3)) : 'No errors') . "\n(last 300 lines of " . implode(', ', $errLogs) . '; cache-rename races, scanner 403s and rate-limit lines are ignored, see Maintenance → Messages you can ignore.)',
            $n > 20 ? 'sudo tail -50 ' . reset($errLogs) : '');
    }
}
// PHP actually serving this request
$phpVer = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$extra = [];
foreach (glob('/etc/php/*/fpm', GLOB_ONLYDIR) ?: [] as $d) {
    $v = basename(dirname($d));
    if ($v !== $phpVer && unit_state("php$v-fpm.service")[1] === 'active') $extra[] = "php$v-fpm";
}
$findings[] = finding('php','nginx', $extra ? 'warn' : 'ok', "PHP $phpVer (" . PHP_SAPI . ') serves the dashboard',
    'This request ran on PHP ' . PHP_VERSION . '.' . ($extra ? ' Also running, but not used by the dashboard: ' . implode(', ', $extra) . '.' : ''),
    $extra ? 'If no other site uses them: sudo systemctl disable --now ' . implode(' ', $extra) : '');
$missingExt = array_values(array_filter(['pdo_mysql', 'mbstring', 'json', 'sockets'], fn($e) => !extension_loaded($e)));
if ($missingExt) {
    $findings[] = finding('php-ext','nginx','warn','PHP extensions missing: ' . implode(', ', $missingExt),
        'pdo_mysql = database pages, mbstring = message text, sockets = BBS/flrig connections.',
        "sudo apt install " . implode(' ', array_map(fn($e) => "php$phpVer-" . ($e === 'pdo_mysql' ? 'mysql' : $e), array_diff($missingExt, ['json', 'sockets']))) . " && sudo systemctl restart php$phpVer-fpm");
}
// Stale nginx configs (in sites-available but not enabled)
if ($isNginx && is_dir('/etc/nginx/sites-available') && is_dir('/etc/nginx/sites-enabled')) {
    $stale = array_values(array_diff(array_map('basename', glob('/etc/nginx/sites-available/*') ?: []),
                                     array_map('basename', glob('/etc/nginx/sites-enabled/*') ?: []), ['default']));
    if ($stale) {
        $findings[] = finding('nginx-stale-configs','nginx','info', count($stale) . ' unused file(s) in sites-available/',
            'Not loaded (not linked from sites-enabled/): ' . implode(', ', array_slice($stale, 0, 8)) . '. Harmless, but easy to edit by mistake: changes there do nothing.',
            'sudo mkdir -p /root/nginx-old-configs && for f in ' . implode(' ', array_map('escapeshellarg', $stale)) . '; do sudo mv "/etc/nginx/sites-available/$f" /root/nginx-old-configs/; done');
    }
}
// PHP-FPM sockets named by the enabled sites (2026-10-30, KG9NI: the
// installer wrote the generic /run/php/php-fpm.sock, which didn't exist on a
// PHP 8.5 server, so every PHP page failed). Each one must exist; the generic
// name is only an alias link, so the versioned path is preferred.
if ($isNginx) {
    $fpSocks = []; $fpFiles = [];
    foreach (array_merge(glob('/etc/nginx/sites-enabled/*') ?: [], glob('/etc/nginx/conf.d/*.conf') ?: []) as $f) {
        if (!is_file($f) || !is_readable($f)) continue;
        if (preg_match_all('~^\s*fastcgi_pass\s+unix:([^;\s]+)\s*;~m', (string)@file_get_contents($f), $fm)) {
            foreach ($fm[1] as $sk) { $fpSocks[$sk] = true; $fpFiles[$sk][basename($f)] = true; }
        }
    }
    $fpSocks = array_keys($fpSocks);
    $fpGone  = array_values(array_filter($fpSocks, fn($p) => !file_exists($p)));
    $fpAlias = array_values(array_filter($fpSocks, fn($p) => !preg_match('~php[0-9]+\.[0-9]+-fpm\.sock$~', $p)));
    $where   = fn(array $l) => implode(', ', array_map(fn($p) => "$p (" . implode(', ', array_keys($fpFiles[$p])) . ')', $l));
    $fpFix   = "sudo sed -i --follow-symlinks 's|unix:/run/php/[^;]*|unix:/run/php/php$phpVer-fpm.sock|' /etc/nginx/sites-enabled/FILE && sudo nginx -t && sudo systemctl reload nginx";
    if (!$fpSocks) {
        // TCP fastcgi (127.0.0.1:9000) or an include we can't see: nothing to check
    } elseif ($fpGone) {
        $findings[] = finding('php-fpm-socket','nginx','crit','nginx points PHP at a socket that does not exist',
            'Missing: ' . $where($fpGone) . '. Pages under those locations answer 502 Bad Gateway. This PHP listens on /run/php/php' . $phpVer . '-fpm.sock.', $fpFix);
    } elseif ($fpAlias) {
        $findings[] = finding('php-fpm-socket','nginx','warn','nginx uses the generic PHP-FPM socket name',
            $where($fpAlias) . ' is an alias link that some systems don\'t create or lose after a PHP upgrade; then every PHP page fails. Name the versioned socket instead.', $fpFix);
    } else {
        $findings[] = finding('php-fpm-socket','nginx','ok','nginx PHP-FPM socket(s) exist', implode(', ', $fpSocks) . '.');
    }
}

// v1.5.79: pages re-checked each visit, so updates show without clearing the browser's cache
if ($isNginx) {
    $pcFiles = []; $pcHave = false;
    foreach (array_merge(glob('/etc/nginx/sites-enabled/*') ?: [], glob('/etc/nginx/conf.d/*.conf') ?: []) as $f) {
        if (!is_file($f) || !is_readable($f)) continue;
        $t = (string)@file_get_contents($f);
        if (strpos($t, $WEB_ROOT) === false && strpos($t, 'bpq') === false) continue;
        $pcFiles[] = basename($f);
        if (strpos($t, '$bpq_page_cache') !== false) $pcHave = true;
    }
    if ($pcFiles && !$pcHave) {
        $findings[] = finding('page-cache','nginx','warn','Browsers may keep old pages after an update',
            'The site file (' . implode(', ', $pcFiles) . ') doesn\'t tell browsers to re-check pages, so after an update a browser can go on showing the old version until its cache is cleared. Add the two v1.5.79 lines from CHANGELOG.md (a "map $sent_http_content_type $bpq_page_cache" block above the server blocks, and "add_header Cache-Control $bpq_page_cache always;" in each server block next to the other add_header lines), then: sudo nginx -t && sudo systemctl reload nginx');
    } elseif ($pcFiles) {
        $findings[] = finding('page-cache','nginx','ok','Pages are re-checked on each visit', 'Updates show without clearing the browser cache.');
    }
}

// ═══════════════════════════════════════════════════════
// 4. DATABASE
// ═══════════════════════════════════════════════════════
$dbUnit = unit_state('mariadb.service')[0] ? 'mariadb' : (unit_state('mysql.service')[0] ? 'mysql' : '');
@include_once $WEB_ROOT . '/tprfn-db.php';
$dbConfigured = function_exists('tprfn_db_settings') && tprfn_db_settings();
if (!$dbConfigured) {
    $findings[] = finding('db','database','info','No database configured',
        'No db-config.php and no db section in config.php. RF Connections, Traffic and System Logs read the log files directly; the Email Client needs the database to keep messages.',
        'To add it: re-run install.sh, or see USER-MANUAL "Database".');
} else {
    $pdo = null;
    try { $pdo = tprfn_db(); } catch (Throwable $e) { $dbErr = $e->getMessage(); }
    if (!$pdo) {
        $st = $dbUnit ? unit_state("$dbUnit.service")[1] : 'not installed';
        $findings[] = finding('db','database','crit','Database connection failed',
            "The pages can't reach the database (" . preg_replace('/\s+/', ' ', $dbErr ?? '') . "). " . ($dbUnit ? "Service $dbUnit: $st." : 'No mariadb or mysql service on this machine.'),
            $st !== 'active' ? "sudo systemctl enable --now " . ($dbUnit ?: 'mariadb') : 'Check user and password in db-config.php against the database (Maintenance → Database & credentials).');
    } else {
        $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $findings[] = finding('db','database','ok','Database connection works',
            "Connected as " . TPRFN_DB_USER . '@' . TPRFN_DB_HOST . '/' . TPRFN_DB_NAME . " (settings from " . (tprfn_db_settings()['source'] ?? '?') . "), server $ver.");
        // Tables and migrations
        $tables = array_flip($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $groups = [
            'Email Client'   => ['bpq_messages', 'sql/2026-07-messages-schema.sql'],
            'RF Connections' => ['rf_connections', 'data/rf-connections-schema.sql'],
            'Traffic'        => ['traffic_stats', 'data/traffic-schema.sql'],
            'System Logs'    => ['sys_events', 'data/system-logs-schema.sql'],
        ];
        $missingT = [];
        foreach ($groups as $label => [$t, $file]) if (!isset($tables[$t])) $missingT[] = "$label ($t → load $file)";
        $cols = function (string $t) use ($pdo, $tables): array {
            if (!isset($tables[$t])) return [];
            return array_flip($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN));
        };
        $mig = [];
        $c = $cols('bpq_msg_folders'); if (isset($tables['bpq_msg_folders']) && !isset($c['keep_on_kill'])) $mig[] = 'sql/2026-09-26-message-keep-and-bodies.sql (📌 keep-after-kill)';
        $c = $cols('rf_messages');
        if (isset($tables['rf_messages']) && !isset($c['medium']))        $mig[] = 'sql/2026-09-21-rf-messages-medium.sql (Forwarded Messages "Via")';
        if (isset($tables['rf_messages']) && !isset($c['accept_status'])) $mig[] = 'sql/2026-10-06-rf-messages-delivery.sql (delivery status)';
        if ($missingT || $mig) {
            $findings[] = finding('db-schema','database','warn','Database tables or updates missing',
                ($missingT ? 'Missing: ' . implode('; ', $missingT) . '. ' : '') . ($mig ? 'Not applied: ' . implode('; ', $mig) . '.' : ''),
                "Back up first (Maintenance → Database), then for each file: sudo mysql " . TPRFN_DB_NAME . " < $WEB_ROOT/FILE   (all are safe to run twice).");
        } else {
            $findings[] = finding('db-schema','database','ok','Database tables up to date',
                'Email Client, RF Connections, Traffic and System Logs tables exist, and the 2026-09/10 updates are applied.');
        }
        // Buffer pool vs data size
        try {
            $pool = (int)$pdo->query("SELECT @@innodb_buffer_pool_size")->fetchColumn();
            $size = (int)$pdo->query("SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
            $findings[] = finding('innodb-buffer','database', $size > $pool ? 'warn' : 'ok',
                'Database ' . round($size / 1048576) . ' MB, buffer pool ' . round($pool / 1048576) . ' MB',
                $size > $pool ? 'The data no longer fits in memory, so big pages (RF Connections, System Logs) read from disk.' : 'The whole database fits in the InnoDB buffer pool.',
                $size > $pool ? 'If RAM allows, raise innodb_buffer_pool_size in /etc/mysql/mariadb.conf.d/50-server.cnf above ' . (ceil($size / 268435456) * 256) . 'M and restart MariaDB.' : '');
        } catch (Throwable $e) {}
    }
    // Bind address (from the listening sockets)
    if (!empty($listen[3306])) {
        $netBind = is_public_bind($listen[3306]);
        $dbPub   = $netBind ? ($fw4chains ? bpq_fw_port_public($fw4chains, 3306) : null) : false;
        $findings[] = finding('mariadb-bind','database', !$netBind ? 'ok' : ($dbPub === false ? 'info' : 'warn'),
            !$netBind ? 'Database listens on localhost only' : ($dbPub === false ? 'Database listens on the network (firewall keeps the internet out)' : 'Database listens on the network'),
            'Port 3306 bound to: ' . implode(', ', array_unique($listen[3306])) . '.'
              . ($netBind ? ($dbPub === false ? ' The firewall refuses it from the internet, so only machines your rules allow (e.g. your LAN) can reach it.' : ' Nothing in the firewall was found to stop it from the internet.') : ''),
            $netBind ? 'Unless another machine uses this database: bind-address = 127.0.0.1 in /etc/mysql/mariadb.conf.d/50-server.cnf, then sudo systemctl restart mariadb. Check first: sudo ss -tnp | grep :3306 (connections from other addresses mean something uses it).' : '');
    }
}

// ═══════════════════════════════════════════════════════
// 5. LOGS & UPDATES
// ═══════════════════════════════════════════════════════

// Security updates (apt list needs no root; it reflects the last apt update)
$upg = sh_quiet('apt list --upgradable');
if ($upg !== '' || is_dir('/var/lib/apt')) {
    $sec = array_values(array_filter(explode("\n", $upg), fn($l) => stripos($l, '-security') !== false));
    $stamp = @filemtime('/var/lib/apt/periodic/update-success-stamp') ?: @filemtime('/var/cache/apt/pkgcache.bin');
    $since = $stamp ? ' (package lists ' . age_str(time() - $stamp) . ' old)' : '';
    $findings[] = $sec
        ? finding('updates','logs', count($sec) > 10 ? 'crit' : 'warn', count($sec) . ' security update(s) waiting',
            implode("\n", array_slice($sec, 0, 8)) . (count($sec) > 8 ? "\n…" : '') . "\n$since",
            'sudo apt update && sudo apt upgrade')
        : finding('updates','logs','ok','No security updates waiting' . $since, 'According to the last apt update.');
}

// /var/log size
$logMB = round((int)sh_quiet('du -sk /var/log 2>/dev/null | cut -f1') / 1024);   // folders www-data can't read aren't counted
$findings[] = finding('log-size','logs', $logMB > 5000 ? 'warn' : 'ok', "/var/log is {$logMB} MB",
    $logMB > 5000 ? "Biggest:\n" . sh_quiet('du -sh /var/log/* 2>/dev/null | sort -rh | head -5') : 'Size is reasonable.',
    $logMB > 5000 ? 'Look for logs without rotation (next row); sudo journalctl --vacuum-size=500M trims the journal.' : '');

// SSH login failures — auth.log is root:adm 640, so usually not readable here
if (is_readable('/var/log/auth.log')) {
    $authFails = (int)sh_quiet('grep -cE "Failed password|Invalid user" /var/log/auth.log');
    $findings[] = finding('auth-fails','logs', ($authFails > 500 && $f2bState[1] !== 'active') ? 'warn' : 'ok', "$authFails failed SSH logins in auth.log",
        $f2bState[1] === 'active' ? 'fail2ban is banning repeat offenders.' : 'Without fail2ban nothing stops the guessing.',
        ($authFails > 500 && $f2bState[1] !== 'active') ? 'sudo apt install fail2ban' : '');
}

// Log rotation for the dashboard's own logs and the VARA log
$lrFiles = [];
foreach ((glob('/etc/logrotate.d/*') ?: []) as $lrf) {
    if (!is_file($lrf) || !is_readable($lrf)) continue;
    $txt = (string)@file_get_contents($lrf, false, null, 0, 65536);
    if (stripos($txt, $WEB_ROOT) !== false || stripos($txt, $LOG_DIR) !== false || preg_match('#bpq|vara#i', $txt)) $lrFiles[] = $lrf;
}
$own = [];
foreach (['dashboard.log','bbs-messages.log','message-bodies.log','nws-auto-post.log','bpq-telnet-daemon.log','bpq-vara-daemon.log','prop-scheduler.log','storm-monitor.log','connect-watchdog.log','link-probe.log'] as $f) {
    // v1.5.19: these now live in /var/log/bpq-dashboard (covered by its *.log);
    // only copies still written into LinBPQ's logs folder need naming
    if (file_exists("$LOG_DIR/$f") && bpq_app_log_dir() === $LOG_DIR) $own[] = "$LOG_DIR/$f";
}
$parserLogs = glob('/var/log/bpq-dashboard/*.log') ?: [];
if ($lrFiles) {
    $findings[] = finding('logrotate','logs','ok','Log rotation configured',
        'Files in /etc/logrotate.d that cover the dashboard / BPQ / VARA: ' . implode(', ', $lrFiles) . '. Check each log below is named in one of them: ' . implode(' ', array_merge($own, $parserLogs ? ['/var/log/bpq-dashboard/*.log'] : [])));
} else {
    $block = ($own ? implode(' ', $own) : "$LOG_DIR/dashboard.log $LOG_DIR/bbs-messages.log")
           . ($parserLogs ? ' /var/log/bpq-dashboard/*.log' : '');
    $findings[] = finding('logrotate','logs','warn','No log rotation for the dashboard\'s logs',
        "No file in /etc/logrotate.d mentions $WEB_ROOT, bpq or vara, so the dashboard's own logs (and the parser logs, written every 5 minutes) grow forever.",
        "Create /etc/logrotate.d/bpq-dashboard:\n$block {\n    weekly\n    rotate 8\n    compress\n    missingok\n    notifempty\n    copytruncate\n}\nName only the dashboard's own files: logs/ is normally LinBPQ's log folder, and the pages read BPQ's dated logs there. Add the VARA log that vara-logger.sh writes (its OUTPUT_FILE) if you run it. Test: sudo logrotate -d /etc/logrotate.d/bpq-dashboard");
}

// Log parsers (cron, every 5 minutes; each run prints a summary to its log).
// 2026-10-25: also checked when they run from a crontab this page can't read
// (www-data's or root's): their log is looked for in the usual places.
$cronFile = '/etc/cron.d/bpq-dashboard';
$cron = is_readable($cronFile) ? (string)@file_get_contents($cronFile) : '';
$parserRows = 0;
foreach (['parse-vara-log' => 'RF Connections', 'parse-traffic-report' => 'Traffic', 'parse-system-logs' => 'System Logs'] as $p => $page) {
    $line = preg_match('/^[^#\n]*' . preg_quote($p, '/') . '\.py[^\n]*$/m', $cron, $m) ? $m[0] : '';
    $logf = ($line && preg_match('/>>\s*(\S+)/', $line, $lm)) ? $lm[1] : null;
    if (!$logf) {
        foreach (["/var/log/bpq-dashboard/$p.log", "/var/log/$p.log", "$LOG_DIR/$p.log"] as $c) {
            if (file_exists($c)) { $logf = $c; break; }
        }
    }
    if ($cron !== '' && !$line) {
        $parserRows++;
        $findings[] = finding("parser-$p",'logs','warn',"$p is not scheduled",
            "$cronFile has no active line for $p.py, so the $page page doesn't get new data.",
            "Copy the line from install.sh step 14 into $cronFile.");
        continue;
    }
    if (!$logf) continue;                       // not found anywhere: summarised below
    $parserRows++;
    if (!is_readable($logf)) {
        $findings[] = finding("parser-$p",'logs','info',"$p log not readable",
            "Its output goes to $logf, which the web server can't read, so the last run can't be checked: sudo tail -n 5 $logf");
        continue;
    }
    $age = time() - filemtime($logf);
    $tail = sh_quiet('tail -n 20 ' . escapeshellarg($logf));
    $bad = '';
    foreach (array_reverse(explode("\n", $tail)) as $tl) {
        if (preg_match('/^(Done|Processed|Parsed|Sessions|  \[DRY)/', $tl)) break;   // last good summary is newer
        if (preg_match('/Traceback|ERROR|Error:|Access denied|No module named/', $tl)) { $bad = $tl; break; }
    }
    $where = $line ? "$cronFile" : 'a crontab';
    if ($age > 1800) {
        $findings[] = finding("parser-$p",'logs','warn',"$p hasn't run for " . age_str($age),
            "Its log $logf was last written " . age_str($age) . " ago; it runs every 5 minutes from $where. The $page page shows old data.",
            "Is cron running? systemctl is-active cron. Where it's scheduled: grep -r $p /etc/cron.d/; sudo crontab -l -u www-data; sudo crontab -l. Run that line by hand as www-data to see the error.");
    } elseif ($bad !== '') {
        $findings[] = finding("parser-$p",'logs','warn',"$p is failing",
            "Last run (" . age_str($age) . " ago) ended with: $bad", "tail -n 30 $logf");
    } else {
        $findings[] = finding("parser-$p",'logs','ok',"$p running",
            "Last run " . age_str($age) . " ago (log $logf), no errors. Feeds the $page page.");
    }
}
if ($parserRows === 0 && $dbConfigured) {
    $findings[] = finding('parsers','logs','info','Log parsers not found',
        "No $cronFile and no parser log in /var/log/bpq-dashboard/, /var/log/ or $LOG_DIR. If they run from a crontab with output elsewhere, check them on the Maintenance page (Log parsers).");
}

// ═══════════════════════════════════════════════════════
// 6. APPLICATION — LinBPQ and the dashboard's services
// ═══════════════════════════════════════════════════════

// LinBPQ — by process, whatever the service is called
// 2026-11-01: the program is also shipped/renamed as linbpq64 or pilinbpq (N3MEL runs linbpq64).
$bpqPids = sh_quiet("pgrep -x 'linbpq|linbpq64|pilinbpq'");
if ($bpqPids !== '') {
    $findings[] = finding('linbpq','application','ok','LinBPQ is running', 'LinBPQ process ' . str_replace("\n", ', ', $bpqPids) . '.');
} else {
    $findings[] = finding('linbpq','application','crit','LinBPQ is not running',
        'No LinBPQ process (linbpq, linbpq64 or pilinbpq): no BBS, no RF, no forwarding, and most pages show errors.',
        'Start it with your service (often: sudo systemctl start bpq). Its log: sudo journalctl -u bpq -n 50');
}

// Dashboard daemons. [unit, what it does, heartbeat file, max heartbeat age, optional?]
$radioOn = ($cfg['radio']['enabled'] ?? true) !== false;
$daemons = [
    ['bpq-telnet', 'the Telnet Client page and YAPP file transfers', "$CACHE_DIR/telnet-sessions/telnet-daemon.json", 120, false],
    ['bpq-chat',   'the Chat page',                                  "$CACHE_DIR/chat-sessions/chat-daemon.json",      90, false],
    ['bpq-aprs',   'the APRS map',                                   "$CACHE_DIR/aprs/aprs-daemon.json",               90, false],
    ['bpq-vara',   'the VARA HF Terminal page',                      "$CACHE_DIR/vara-sessions/vara-daemon.json",     120, true],
    ['vara-logger','the VARA log RF Connections is built from',       null, 0, true],
    ['vara-validator','the callsign check in front of VARA HF',       null, 0, true],
];
if ($radioOn) $daemons[] = ['rig-history-poller', 'round-the-clock TX power / SWR history (RF Power charts)', null, 0, true];
// v1.5.19: pages the station switched off in config.php 'pages' (installer
// page choices). Their helper is 'not in use', whatever its state, unless it
// is running anyway. With the VARA log fetched from the TPRFN server
// ('vara_remote'), vara-logger isn't needed.
$pagesOn  = bpq_pages();
$unitPage = ['bpq-telnet' => 'telnet', 'bpq-chat' => 'chat', 'bpq-aprs' => 'aprs',
             'bpq-vara' => 'vara', 'vara-validator' => 'vara_validator'];
$varaRemote = !empty($cfg['vara_remote']['enabled']);
foreach ($daemons as [$u, $what, $hb, $maxAge, $optional]) {
    [$inst, $act, $en] = unit_state("$u.service");
    $pageOff = (isset($unitPage[$u]) && !$pagesOn[$unitPage[$u]]) || ($u === 'vara-logger' && $varaRemote);
    if ($pageOff && $act !== 'active') {
        $findings[] = finding("svc-$u",'application','info',"$u not in use",
            $u === 'vara-logger'
                ? "The VARA HF log comes from the TPRFN server (config.php 'vara_remote'), so vara-logger isn't needed."
                : "The page it serves ($what) is switched off in config.php 'pages', so $u isn't needed.",
            'To use it: Maintenance > Optional pages.');
        continue;
    }
    if (!$inst) {
        $findings[] = finding("svc-$u",'application','info',"$u not installed",
            "No $u.service, so " . ($optional ? "$what is not in use (optional)." : "$what won't work. The installer asks about it in step 9; skip this if you don't use that page."),
            "sudo sed 's|/var/www/bpq-dashboard|$WEB_ROOT|g' $WEB_ROOT/$u.service | sudo tee /etc/systemd/system/$u.service >/dev/null && sudo systemctl daemon-reload && sudo systemctl enable --now $u");
        continue;
    }
    if ($act !== 'active') {
        // 2026-11-01: a helper the station switched off (disabled) is 'not in
        // use', not a fault, for every helper, not just the optional ones. One
        // that is enabled but not running is meant to run and is down: critical.
        if ($en !== 'enabled') {
            $findings[] = finding("svc-$u",'application','info',"$u is switched off",
                "$u.service is installed but $en, so $what is not in use. That's fine if you don't use it.",
                "Only if you want it: sudo systemctl enable --now $u");
        } else {
            $findings[] = finding("svc-$u",'application','crit',"$u is $act",
                "$u.service is enabled but $act, so $what is down (it failed or keeps restarting).",
                "sudo journalctl -u $u -n 30 --no-pager; sudo systemctl restart $u");
        }
        continue;
    }
    if ($hb === null) {
        $findings[] = finding("svc-$u",'application','ok',"$u is running", 'Runs ' . $what . '.');
        continue;
    }
    $hbAge = file_exists($hb) ? time() - filemtime($hb) : null;
    $data = json_file($hb);
    if ($hbAge === null) {
        $findings[] = finding("svc-$u",'application','warn',"$u running, no status file yet",
            "$u.service is active but hasn't written " . str_replace("$WEB_ROOT/", '', $hb) . ". If it stays missing, the daemon can't write to cache/ or never got going.",
            "sudo journalctl -u $u -n 30 --no-pager");
    } elseif ($hbAge > $maxAge) {
        $why = $u === 'bpq-chat' ? ' The chat daemon writes it only while it is connected to BPQ chat, so it has lost that connection (it retries by itself).' : '';
        $findings[] = finding("svc-$u",'application','warn',"$u status " . age_str($hbAge) . ' old',
            "$u.service is active but last updated its status " . age_str($hbAge) . " ago (normally under {$maxAge}s).$why",
            "sudo journalctl -u $u -n 30 --no-pager; sudo systemctl restart $u");
    } else {
        $detail = 'Runs ' . $what . '. Status updated ' . age_str($hbAge) . ' ago.';
        $st = 'ok';
        if ($u === 'bpq-aprs' && $data) {
            $detail .= ' APRS-IS ' . (!empty($data['connected']) ? 'connected' : 'NOT connected')
                     . (isset($data['call']) ? " as {$data['call']}" : '')
                     . (array_key_exists('verified', $data) ? (!empty($data['verified']) ? ' (verified)' : ' (receive-only / not verified)') : '')
                     . ', ' . (int)($data['stations'] ?? 0) . ' stations.';
            if (empty($data['connected'])) $st = 'warn';
            $b = $data['beacon'] ?? [];
            if (!empty($b['enabled'])) {
                $bs = (string)($b['state'] ?? '');
                $detail .= " BBS beacon: " . ($bs === 'ok' ? 'sending' . (isset($b['last_sent']) ? ', last ' . age_str(time() - (int)$b['last_sent']) . ' ago' : '') : $bs) . '.';
                if ($bs !== 'ok') $st = 'warn';
            }
        }
        if ($u === 'bpq-telnet' && $data) $detail .= ' Open sessions: ' . (int)($data['active_sessions'] ?? 0) . '.';
        $findings[] = finding("svc-$u",'application',$st,"$u is running", $detail,
            $st === 'warn' ? "APRS details: sudo journalctl -u bpq-aprs -n 30 --no-pager; login and passcode are config.php 'aprs'." : '');
    }
}

// Timers: message text and bulletin clean-up
foreach (['message-bodies' => 'saves the text of new BBS messages every 5 minutes',
          'bulletin-retention' => 'deletes bulletins older than 14 days once a day'] as $t => $what) {
    [$inst, $act] = unit_state("$t.timer");
    if (!$inst) {
        $findings[] = finding("timer-$t",'application', $dbConfigured ? 'warn' : 'info', "$t.timer not installed",
            "Nothing $what." . ($dbConfigured ? '' : ' (Needs the database.)'),
            $dbConfigured ? "sudo sed 's|/var/www/bpq-dashboard|$WEB_ROOT|g' $WEB_ROOT/$t.service | sudo tee /etc/systemd/system/$t.service >/dev/null && sudo sed 's|/var/www/bpq-dashboard|$WEB_ROOT|g' $WEB_ROOT/$t.timer | sudo tee /etc/systemd/system/$t.timer >/dev/null && sudo systemctl daemon-reload && sudo systemctl enable --now $t.timer" : '');
        continue;
    }
    $res  = sh_quiet("systemctl show $t.service -p Result --value");
    $last = sh_quiet("systemctl show $t.service -p ExecMainExitTimestamp --value");
    if ($act !== 'active') {
        $findings[] = finding("timer-$t",'application','warn',"$t.timer is $act", "Installed but not running: nothing $what.", "sudo systemctl enable --now $t.timer");
    } elseif ($res !== '' && $res !== 'success') {
        $findings[] = finding("timer-$t",'application','warn',"$t: last run failed ($res)",
            ucfirst($what) . ". Last run: " . ($last ?: 'unknown') . '.', "sudo journalctl -u $t.service -n 20 --no-pager");
    } else {
        $findings[] = finding("timer-$t",'application','ok',"$t.timer active", ucfirst($what) . '. Last run: ' . ($last ?: 'not yet') . '.');
    }
}

// NWS automatic bulletins (2026-10-26): only checked when switched on in config.php
$nwsAuto = (array)($cfg['nws']['auto_post'] ?? []);
if (!empty($nwsAuto['enabled'])) {
    $nwsArea = strtoupper((string)($nwsAuto['area'] ?? ''));
    $nwsDest = strtoupper((string)($nwsAuto['destination'] ?? '')) ?: "WX@$nwsArea";
    $what = "posts new NWS Warnings/Watches for $nwsArea to $nwsDest every 5 minutes";
    [$inst, $act] = unit_state('nws-auto-post.timer');
    if (!preg_match('/^[A-Z]{2}$/', $nwsArea)) {
        $findings[] = finding('timer-nws-auto-post','application','warn','NWS auto-post: no state set',
            "config.php nws.auto_post is enabled but 'area' is not a 2-letter state code, so nothing is posted.",
            "Set 'area' => 'GA' (your state) in config.php 'nws' => 'auto_post'.");
    } elseif (!$inst) {
        $findings[] = finding('timer-nws-auto-post','application','warn','nws-auto-post.timer not installed',
            "config.php has automatic NWS bulletins switched on, but nothing $what.",
            "sudo sed 's|/var/www/bpq-dashboard|$WEB_ROOT|g' $WEB_ROOT/nws-auto-post.service | sudo tee /etc/systemd/system/nws-auto-post.service >/dev/null && sudo cp $WEB_ROOT/nws-auto-post.timer /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now nws-auto-post.timer");
    } elseif ($act !== 'active') {
        $findings[] = finding('timer-nws-auto-post','application','warn',"nws-auto-post.timer is $act", "Installed but not running: nothing $what.", 'sudo systemctl enable --now nws-auto-post.timer');
    } else {
        $res  = sh_quiet('systemctl show nws-auto-post.service -p Result --value');
        $last = sh_quiet('systemctl show nws-auto-post.service -p ExecMainExitTimestamp --value');
        if ($res !== '' && $res !== 'success') {
            $findings[] = finding('timer-nws-auto-post','application','warn',"nws-auto-post: last run failed ($res)",
                ucfirst($what) . '. Last run: ' . ($last ?: 'unknown') . '. A failed run is usually the BBS or api.weather.gov not answering; it retries every run.',
                'sudo journalctl -u nws-auto-post.service -n 20 --no-pager');
        } else {
            $findings[] = finding('timer-nws-auto-post','application','ok','nws-auto-post.timer active', ucfirst($what) . '. Last run: ' . ($last ?: 'not yet') . '.');
        }
    }
}

// v1.5.19: VARA HF log downloaded from the TPRFN server (config.php vara_remote)
if (!empty($cfg['vara_remote']['enabled'])) {
    [$inst, $act] = unit_state('vara-remote.timer');
    $vrs = json_file("$CACHE_DIR/vara-remote-status.json") ?: [];
    $age = !empty($vrs['last_success']) ? time() - (int)$vrs['last_success'] : null;
    $url = (string)($cfg['vara_remote']['url'] ?? '');
    if (!$inst || $act !== 'active') {
        $findings[] = finding('timer-vara-remote','application','warn','VARA log download is ' . ($inst ? $act : 'not installed'),
            "config.php vara_remote is on, but vara-remote.timer isn't running, so RF Connections gets nothing new from $url.",
            $inst ? 'sudo systemctl enable --now vara-remote.timer'
                  : "sudo sed 's|/var/www/bpq-dashboard|$WEB_ROOT|g' $WEB_ROOT/vara-remote.service | sudo tee /etc/systemd/system/vara-remote.service >/dev/null && sudo cp $WEB_ROOT/vara-remote.timer /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now vara-remote.timer");
    } elseif ($age === null || $age > 1800) {
        $findings[] = finding('timer-vara-remote','application','warn','VARA log download failing',
            ($age === null ? 'No successful download yet' : 'Last good download ' . round($age / 60) . ' min ago') . " from $url"
            . (!empty($vrs['error']) ? '. Last error: ' . $vrs['error'] : '') . '. RF Connections shows the last good copy.',
            'Log Viewer → VARA download; test: sudo -u www-data python3 ' . $WEB_ROOT . '/scripts/fetch-vara-remote.py -v');
    } else {
        $findings[] = finding('timer-vara-remote','application','ok','VARA log download working',
            'Last download ' . round($age / 60) . " min ago from $url (" . (int)($vrs['bytes'] ?? 0) . ' bytes).');
    }
}

// flrig (Rig Status, RF Power Monitor)
if ($radioOn) {
    $fh = (string)($cfg['radio']['flrig_host'] ?? '127.0.0.1');
    $fp = (int)($cfg['radio']['flrig_port'] ?? 12345);
    $s = @fsockopen($fh, $fp, $en_, $es_, 2);
    if ($s) { fclose($s);
        $findings[] = finding('flrig','application','ok',"flrig answers at $fh:$fp", 'Rig Status and RF Power Monitor can read the radio.');
        // v1.5.93: flrig answering is not flrig reaching the radio. If the
        // radio's USB serial port drops off and comes back (a new ttyUSB
        // node), flrig keeps the old, dead one: it still answers, but
        // can't tune the radio, so LinBPQ's scanning stops.
        $fs = flrig_stale_port();
        if ($fs) {
            $findings[] = finding('flrig-port','application', $fs['sure'] ? 'crit' : 'warn',
                $fs['sure'] ? "flrig is still on the radio's old serial port" : 'A USB serial port came back after flrig started',
                ($fs['sure']
                    ? "flrig started " . $fs['started'] . " and uses {$fs['port']}, which became {$fs['dev']} at " . $fs['dev_at'] . ' when the radio\'s USB dropped off and came back.'
                    : "{$fs['dev']} ({$fs['port']}) appeared at " . $fs['dev_at'] . ', after flrig started (' . $fs['started'] . "). flrig's settings couldn't be read to tell whether it is the radio's port.")
                . ' flrig keeps answering but can\'t reach the radio, so frequency changes (and LinBPQ scanning) stop. Data2G or another modem may also have lost the radio\'s sound card at the same time.',
                "In flrig on the desktop: Config → Setup → Transceiver → Init (or close and reopen flrig). Then check Data2G: ss -ltnp | grep 8500, and if nothing: sudo systemctl restart data2g. Recurring? Check the USB hub, its power and the cables, and ferrites against RF.");
        }
    } else {
        $findings[] = finding('flrig','application','warn',"flrig not reachable at $fh:$fp",
            'Rig Status and RF Power Monitor show no readings. Either flrig isn\'t running or config.php radio.flrig_host / flrig_port is wrong.',
            "pgrep -af flrig; sudo systemctl status flrig. No radio on this station? Set 'radio' => ['enabled' => false] in config.php.");
    }
}

// v1.5.93: modems still holding USB devices that dropped off and came back.
// When the radio's USB (sound card or serial port) disconnects and returns,
// the device gets a new node; a modem that was already running keeps the
// old one and goes deaf (and may not key) while still looking healthy.
foreach (stale_modems() as $sm) {
    $findings[] = finding('modem-stale-' . $sm['id'], 'application', 'warn',
        "{$sm['name']} started before the radio's USB devices last came back",
        "{$sm['name']} has been running since {$sm['started']}; {$sm['dev']} came back at {$sm['dev_at']} (USB disconnect and reconnect). "
        . "It is probably still on the old device: it can't hear the radio (or key it), though it still answers LinBPQ.",
        $sm['fix'] . '. LinBPQ reconnects by itself. Recurring? Check the USB hub, its power and cables, and ferrites against RF.');
}

// Callsign lookups (QRZ / callook) — failures recorded by callsign-lookup.php
$lh = json_file("$CACHE_DIR/lookup-health.json");
if ($lh && !empty($lh['last_failure_at'])) {
    $ago = time() - (int)$lh['last_failure_at'];
    $why = implode(', ', array_slice(array_map(fn($c, $w) => "$c ($w)", array_keys((array)($lh['last_failure'] ?? [])), (array)($lh['last_failure'] ?? [])), 0, 3));
    $findings[] = finding('lookups','application', $ago < 86400 ? 'warn' : 'ok',
        $ago < 86400 ? 'Callsign lookups failed ' . age_str($ago) . ' ago' : 'No callsign lookup failures in the last day',
        "Last failure " . age_str($ago) . " ago: $why. Known station locations are kept when a lookup fails.",
        $ago < 86400 ? "sudo -u www-data php $WEB_ROOT/callsign-lookup.php --qrz-test   (checks the config.php 'qrz' login)" : '');
}

// Web root ownership, cache folder
$owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($WEB_ROOT))['name'] ?? '?') : sh_quiet('stat -c %U ' . escapeshellarg($WEB_ROOT));
$me    = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
$findings[] = $owner === $me
    ? finding('webroot-perms','application','ok',"Web root owned by $me", "$WEB_ROOT is owned by the web server account, as the installer sets it.")
    : finding('webroot-perms','application','warn',"Web root owned by $owner", "$WEB_ROOT belongs to $owner, not $me (the web server). Files copied in by hand may not be writable where the dashboard needs them.",
        "sudo WEB_ROOT=$WEB_ROOT bash $WEB_ROOT/fix-permissions.sh --dry-run   (then without --dry-run)");
$notW = [];
foreach (['cache', 'cache/aprs', 'cache/chat-sessions', 'cache/telnet-sessions', 'data', 'data/stations', 'data/sends'] as $d) {
    if (is_dir("$WEB_ROOT/$d") && !is_writable("$WEB_ROOT/$d")) $notW[] = $d;
}
if (!is_dir($CACHE_DIR)) {
    $findings[] = finding('cache-dir','application','crit','cache/ folder missing',
        "$CACHE_DIR doesn't exist: callsign lookups, the forwarding queue, the daemons' status files and several proxies fail.",
        "sudo mkdir -p $CACHE_DIR && sudo WEB_ROOT=$WEB_ROOT bash $WEB_ROOT/fix-permissions.sh");
} elseif ($notW) {
    $findings[] = finding('cache-dir','application','crit','Folders the dashboard writes to are read-only',
        'PHP cannot write to: ' . implode(', ', $notW) . '.', "sudo WEB_ROOT=$WEB_ROOT bash $WEB_ROOT/fix-permissions.sh");
} else {
    $findings[] = finding('cache-dir','application','ok','cache/ and data/ are writable', 'PHP can write to the folders it needs.');
}

// YAPP received files
$yappDir = "$WEB_ROOT/yapp-files";
if (is_dir($yappDir) && is_readable($yappDir)) {
    $sz = 0; $cnt = 0;
    foreach (glob("$yappDir/*") ?: [] as $f) if (is_file($f)) { $sz += filesize($f); $cnt++; }
    $mb = round($sz / 1048576, 1);
    $findings[] = finding('yapp-files','application', $mb >= 500 ? 'warn' : 'ok', "YAPP received files: $cnt, {$mb} MB",
        "$yappDir. Nothing deletes them automatically.",
        $mb >= 500 ? "find $yappDir -type f -mtime +30 -print   (then -delete instead of -print)" : '');
}

// v1.5.35: Connect Watchdog (optional): running, paused partners, unmatched tiles
$wdFile = $WEB_ROOT . '/cache/watchdog-state.json';
if (is_readable($wdFile) && is_array($wd = json_decode((string)@file_get_contents($wdFile), true)) && isset($wd['partners'])) {
    $age = time() - (strtotime((string)($wd['updated_at'] ?? '')) ?: 0);
    $paused = $missing = [];
    foreach ((array)$wd['partners'] as $blk => $p) {
        if (!empty($p['paused'])) $paused[] = $blk . ' until ' . str_replace('T', ' ', substr((string)($p['resume_at'] ?? ''), 0, 16)) . ' UTC';
        if (($p['status'] ?? '') === 'not_in_linmail') $missing[] = (string)($p['call'] ?? $blk);
    }
    if ($age > 1800) {
        $findings[] = finding('watchdog','application','warn','Connect Watchdog not running',
            'Its state was last written ' . round($age / 60) . ' minutes ago (it runs every 5 minutes).',
            'sudo crontab -l | grep connect-watchdog   # then: sudo tail -20 /var/log/bpq-dashboard/connect-watchdog.log');
    } elseif ($paused) {
        $findings[] = finding('watchdog','application','info','Connect Watchdog: ' . count($paused) . ' partner(s) paused',
            'Paused after failed forwarding attempts: ' . implode('; ', $paused) . '. They resume by themselves.',
            'sudo python3 ' . $WEB_ROOT . '/scripts/connect-watchdog.py --resume CALL   # to resume one now');
    } else {
        $findings[] = finding('watchdog','application','ok','Connect Watchdog running',
            'Watching ' . count((array)$wd['partners']) . ' RF Forwarding Partner(s); none paused.', '');
    }
    if ($missing) {
        $findings[] = finding('watchdog-match','application','warn','Connect Watchdog: partner(s) not in linmail.cfg',
            'RF Forwarding Partners with no forwarding entry of that name, so not watched: ' . implode(', ', $missing) . '.',
            'Fix the call on RF Connections → RF Forwarding Partners (N3MEL-2 looks for the linmail.cfg entry N3MEL).');
    }
}

// v1.5.82: forwarding scripts whose TIMES bands leave hours with no forwarding
require_once dirname(__DIR__) . '/includes/fwd-times.php';
$ft = bpq_fwd_times_report();
if ($ft['readable']) {
    $gapped = [];
    foreach ($ft['partners'] as $name => $p) {
        if ($p['enabled'] === false || !$p['gaps']) continue;
        $gapped[] = $name . ': ' . implode(', ', array_map('bpq_fwd_span', $p['gaps']))
                  . ' (' . round(array_sum(array_map('bpq_fwd_len', $p['gaps'])) / 60, 1) . ' h a day)';
    }
    $timed = count(array_filter($ft['partners'], fn($p) => $p['bands'] && $p['enabled'] !== false));
    if ($gapped) {
        $findings[] = finding('fwd-times','application','warn','Forwarding scripts with hours not covered',
            'No TIMES band covers these hours (UTC), so LinBPQ doesn\'t forward to the partner then: it "connects", has nothing to run and hangs up, and the mail waits (or leaves another way, e.g. NetRom). ' . implode('; ', $gapped) . '.',
            'If that isn\'t on purpose, widen a band in the partner\'s ConnectScript in linmail.cfg so the bands meet (e.g. 0000-1259 then 1300-2359). Edit only with LinBPQ stopped: sudo systemctl stop bpq, edit, sudo systemctl start bpq. Maintenance → Forwarding schedule gaps.');
    } elseif ($timed) {
        $findings[] = finding('fwd-times','application','ok','Forwarding scripts cover the whole day',
            "$timed partner script(s) with TIMES bands, no hours left out.");
    }
} elseif ($ft['path'] !== '' && @file_exists($ft['path'])) {
    $findings[] = finding('fwd-times','application','info','Forwarding schedule not checked',
        'The web server can\'t read ' . $ft['path'] . ', so System Audit can\'t check its TIMES bands.',
        'Check by hand: sudo grep -n ConnectScript ' . $ft['path'] . ' (don\'t loosen its permissions: linmail.cfg can hold mail-server passwords).');
}

// v1.5.34: the station's backup, if a backup script reports to the dashboard
require_once dirname(__DIR__) . '/includes/station-config.php';
$bk = bpq_backup_status();
if (!$bk['present']) {
    $findings[] = finding('backup','application','info','No backup set up (optional, recommended)',
        "Nothing reports a backup to the dashboard ({$bk['file']}: " . ($bk['why'] ?? '') . '). A nightly backup to a separate drive protects LinBPQ, the databases and the system settings (Installation Manual §10.16).',
        'sudo bash ' . $WEB_ROOT . '/scripts/bpq-backup-setup.sh');
} else {
    $when = ($bk['last_ok_at'] ?? '') !== '' ? "Last good backup {$bk['last_ok_at']} ({$bk['age_hours']} h ago)" : 'No good backup recorded';
    $drv  = isset($bk['drive_free_gb']) ? "; drive {$bk['drive_used_pct']}% used, {$bk['drive_free_gb']} GB free" : (($bk['drive_mounted'] ?? null) === false ? '; backup drive not mounted' : '');
    $lvl  = ['ok' => 'ok', 'stale' => 'warn', 'full' => 'warn', 'failed' => 'crit'][$bk['verdict']] ?? 'warn';
    $title = ['ok' => 'Backup up to date', 'stale' => 'Backup is older than ' . $bk['max_age_hours'] . ' h', 'full' => 'Backup drive nearly full', 'failed' => 'Last backup failed'][$bk['verdict']] ?? 'Backup';
    $findings[] = finding('backup','application',$lvl,$title,
        $when . $drv . (($bk['result'] ?? '') === 'failed' && ($bk['message'] ?? '') !== '' ? '. Last run: ' . $bk['message'] : '') . '.',
        $lvl === 'ok' ? '' : 'Look at the backup script\'s log; check the drive is plugged in and mounted.');
}

// v1.5.50: scheduled test connects to watched stations (scripts/link-probe.py,
// started by parse-system-logs.py every 5 minutes)
if (!empty($pdo)) {
    foreach (bpq_watch_list() as $w) {
        if (empty($w['probe_hours']) || empty($w['port'])) continue;
        try {
            $st = $pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, MAX(probed_at), UTC_TIMESTAMP()) FROM link_probes WHERE callsign = ? AND port = ?');
            $st->execute([$w['call'], $w['port']]);
            $age = $st->fetchColumn();
        } catch (Throwable $e) {
            $findings[] = finding('link-probe','logs','warn','Test connects: no link_probes table',
                'Watched stations have Test connect set, but the table for the results is missing.', 'Run update.sh again');
            break;
        }
        $late = $age === null || $age === false || (int)$age > $w['probe_hours'] * 60 * 2 + 30;
        $findings[] = finding('link-probe-' . strtolower($w['call']),'logs', $late ? 'warn' : 'ok',
            $late ? "Test connects to {$w['call']} not running" : "Test connects to {$w['call']} running",
            ($age === null || $age === false ? 'None recorded yet' : "Last one {$age} min ago")
            . " (every {$w['probe_hours']} h on port {$w['port']}). They start from parse-system-logs.py's 5-minute run.",
            $late ? 'sudo tail -20 /var/log/bpq-dashboard/link-probe.log; sudo -u www-data python3 ' . $WEB_ROOT . '/scripts/link-probe.py --list' : '');
    }
}

// v1.5.43: Mercury HF modem (optional): running, LinBPQ attached, audio, kept local
require_once dirname(__DIR__) . '/includes/mercury-status.php';
$mst = bpq_mercury_status($WEB_ROOT . '/cache');
if (!empty($mst['enabled'])) {
    $mp = $mst['tnc_port'];
    $where = 'TNC port ' . $mp . ($mst['bpq_port'] ? ', LinBPQ port ' . $mst['bpq_port']['num'] : ', no LinBPQ port with ADDR 127.0.0.1 ' . $mp);
    $lvl = ['up' => 'ok', 'session' => 'ok', 'nolink' => 'warn', 'audio' => 'warn', 'down' => 'warn'][$mst['status']] ?? 'info';
    $title = ['up' => 'Mercury HF running', 'session' => 'Mercury HF running (in a session)', 'nolink' => 'Mercury HF: LinBPQ not attached',
              'audio' => 'Mercury HF: sound card delivers no audio', 'down' => 'Mercury HF not running'][$mst['status']] ?? 'Mercury HF: status unknown';
    $rec = ['nolink' => 'Check the Mercury port\'s "ADDR 127.0.0.1 ' . $mp . '" line in bpq32.cfg, then restart LinBPQ.',
            'audio'  => 'sudo tail -20 /var/log/mercury/mercury.log   # if the card is shared with VARA, check VARA is running',
            'down'   => 'sudo systemctl status mercury'][$mst['status']] ?? '';
    $findings[] = finding('mercury','application',$lvl,$title,
        'Mercury' . ($mst['version'] ? ' ' . $mst['version'] : '') . ", $where" . ($mst['errors_24h'] ? "; {$mst['errors_24h']} error(s) in its log in 24 h" : '')
        . ($mst['log_there'] && !$mst['log_readable'] ? '; its log is not readable by the web server' : '') . '.', $rec);
    // Mercury's TNC answers on every network interface and has no password:
    // anyone who reaches it can key the transmitter.
    if ($mst['listening']) {
        $range = $mp . ':' . ($mp + 2);
        $blocked = $fw4ok && preg_match('/(DROP|REJECT).*(dports\s+' . $mp . '[:,]|dpt:' . $mp . '\b)/', (string)$fw4);
        $findings[] = finding('mercury-exposed','security', $blocked ? 'ok' : ($fw4ok ? 'warn' : 'info'),
            $blocked ? 'Mercury TNC kept local (firewall)' : 'Mercury TNC may be reachable from the network',
            "Mercury listens on all interfaces on $mp-" . ($mp + 2) . ' with no password; anyone who reaches it can key the transmitter.'
            . ($fw4ok ? '' : ' (www-data may not read iptables here, so this could not be checked.)'),
            $blocked ? '' : "sudo iptables -I INPUT -p tcp -m multiport --dports $range ! -i lo -j DROP && sudo netfilter-persistent save");
    }
    // v1.5.49: Mercury's own monitor page (ui_enabled) also listens on every
    // interface with no password, and its full page can change Mercury's
    // audio, PTT and TX level. Keep it to this machine.
    $mset = bpq_mercury_settings();
    if (!empty($mset['ui_enabled'])) {
        $up = (int)$mset['ui_port'];
        $uBlocked = $fw4ok && preg_match('/(DROP|REJECT).*(dpt:' . $up . '\b|dports?\s+[\d,:]*\b' . $up . '\b)|(dpt:|dports?\s+[\d,:]*\b)' . $up . '\b.*(DROP|REJECT)/', (string)$fw4);
        $findings[] = finding('mercury-ui-exposed','security', $uBlocked ? 'ok' : ($fw4ok ? 'warn' : 'info'),
            $uBlocked ? "Mercury monitor (port $up) kept local (firewall)" : "Mercury monitor (port $up) may be reachable from the network",
            "mercury.ini has ui_enabled = true: Mercury serves its monitor pages on port $up on all interfaces, with no password. Its full page (mercury.html) can change Mercury's audio devices, PTT and TX level."
            . ($fw4ok ? '' : ' (www-data may not read iptables here, so this could not be checked.)'),
            $uBlocked ? '' : "sudo iptables -I INPUT -p tcp --dport $up ! -i lo -j DROP && sudo netfilter-persistent save");
    }
    // v1.5.46: Mercury's calls reach RF Connections (parse-mercury-log.py,
    // run by the VARA parser every 5 minutes)
    if (!empty($pdo) && $mst['log_there']) {
        try {
            $hasModem = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                          AND TABLE_NAME = 'rf_connections' AND COLUMN_NAME = 'modem'")->fetchColumn() > 0;
            if (!$hasModem) {
                $findings[] = finding('mercury-parser','logs','warn','Mercury calls not in RF Connections yet',
                    'The database upgrade that adds the modem column to rf_connections has not run, so parse-mercury-log.py stops.',
                    'Run update.sh again, or: sudo mysql <db> < ' . $WEB_ROOT . '/sql/2026-11-14-rf-connections-modem.sql');
            } else {
                $st = $pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, last_parsed_at, NOW()) FROM rf_parser_state WHERE log_file = ?');
                $st->execute([basename(bpq_mercury_settings()['log'])]);
                $age = $st->fetchColumn();
                $n = (int)$pdo->query("SELECT COUNT(*) FROM rf_connections WHERE modem = 'mercury'")->fetchColumn();
                $ok = $age !== false && $age !== null && (int)$age <= 30;
                $findings[] = finding('mercury-parser','logs', $ok ? 'ok' : 'warn',
                    $ok ? 'Mercury calls read into RF Connections' : 'Mercury log not read into RF Connections',
                    $ok ? "$n Mercury call(s) in the table; last read {$age} min ago."
                        : ($age === false || $age === null ? 'parse-mercury-log.py has never finished a run.' : "Last read $age min ago.")
                          . ' The VARA parser runs it every 5 minutes; its line is in parse-vara-log.log.',
                    $ok ? '' : 'sudo -u www-data python3 ' . $WEB_ROOT . '/scripts/parse-mercury-log.py --dry-run');
            }
        } catch (Throwable $e) { /* database checks above report it */ }
    }
}

// v1.5.56: Data2G HF modem (optional): running, LinBPQ attached, audio, kept local, parser
require_once dirname(__DIR__) . '/includes/data2g-status.php';
$dst = bpq_data2g_status($WEB_ROOT . '/cache');
if (!empty($dst['enabled'])) {
    $dp = $dst['tnc_port'];
    $where = 'TNC port ' . $dp . ($dst['bpq_port'] ? ', LinBPQ port ' . $dst['bpq_port']['num'] : ', no LinBPQ port with ADDR 127.0.0.1 ' . $dp);
    $lvl = ['up' => 'ok', 'session' => 'ok', 'noport' => 'crit', 'nolink' => 'warn', 'audio' => 'warn', 'down' => 'warn'][$dst['status']] ?? 'info';
    $title = ['up' => 'Data2G HF running', 'session' => 'Data2G HF running (in a session)', 'noport' => "Data2G HF running but its command port $dp isn't open", 'nolink' => 'Data2G HF: LinBPQ not attached',
              'audio' => 'Data2G HF: sound card input ended', 'down' => 'Data2G HF not running'][$dst['status']] ?? 'Data2G HF: status unknown';
    $rec = ['noport' => 'Data2G hung while starting (often after the radio\'s USB sound card dropped off and came back), so LinBPQ can\'t use it and a scanning radio stops. sudo systemctl restart data2g; then ss -ltnp | grep ' . $dp . ' should show it listening. LinBPQ reconnects by itself.',
            'nolink' => 'Check the Data2G port\'s "ADDR 127.0.0.1 ' . $dp . '" line in bpq32.cfg, then restart LinBPQ.',
            'audio'  => 'sudo tail -20 /var/log/data2g/data2g.log; sudo systemctl restart data2g',
            'down'   => 'sudo systemctl status data2g'][$dst['status']] ?? '';
    $tzNote = '';
    if ($dst['tz'] !== 'UTC' && date_default_timezone_get() !== 'UTC' && trim((string)@file_get_contents('/etc/timezone')) !== 'Etc/UTC') {
        $tzNote = '; the service has no TZ=UTC, so its log may be in local time (the dashboard reads it as UTC)';
        if ($lvl === 'ok') { $lvl = 'warn'; $rec = 'Add Environment=TZ=UTC to /etc/systemd/system/data2g.service, then sudo systemctl daemon-reload && sudo systemctl restart data2g'; }
    }
    $findings[] = finding('data2g','application',$lvl,$title,
        'Data2G' . ($dst['version'] ? ' ' . $dst['version'] : '') . ' (' . ($dst['process'] ?: 'process not seen') . "), $where"
        . ($dst['errors_24h'] ? "; {$dst['errors_24h']} error(s) in its log in 24 h" : '')
        . ($dst['log_there'] && !$dst['log_readable'] ? '; its log is not readable by the web server' : '') . $tzNote . '.', $rec);
    // Data2G has no password on its TNC either, but listens where --listen
    // says (127.0.0.1 by default). Check the service keeps it there.
    $dset = bpq_data2g_settings();
    $exec = '';
    if (@is_readable($dset['unit'])) {
        $u = preg_replace('/\\\\\r?\n\s*/', ' ', (string)@file_get_contents($dset['unit']));
        if (preg_match('/^\s*ExecStart\s*=\s*(.+)$/m', $u, $m)) $exec = $m[1];
    }
    if ($exec !== '') {
        $lis = preg_match('/--listen[ =](\S+)/', $exec, $m) ? $m[1] : '127.0.0.1';
        $local = in_array($lis, ['127.0.0.1', 'localhost', '::1'], true);
        $findings[] = finding('data2g-local','security', $local ? 'ok' : 'warn',
            $local ? 'Data2G TNC kept local (--listen 127.0.0.1)' : "Data2G TNC listens on $lis",
            $local ? "Its command, data and KISS ports ($dp, " . ($dp + 1) . ", {$dst['kiss_port']}) answer this machine only."
                   : "Data2G's TNC has no password: anyone who reaches $dp can key the transmitter.",
            $local ? '' : "Set --listen 127.0.0.1 in /etc/systemd/system/data2g.service (then daemon-reload, restart data2g), or: sudo iptables -I INPUT -p tcp -m multiport --dports $dp:" . ($dp + 1) . ",{$dst['kiss_port']} ! -i lo -j DROP && sudo netfilter-persistent save");
        if ($dst['monitor']) {
            $wp = (int)$dst['monitor']['port'];
            $wl = $dst['monitor']['local_only'];
            $wBlocked = $fw4ok && preg_match('/(DROP|REJECT).*(dpt:' . $wp . '\b|dports?\s+[\d,:]*\b' . $wp . '\b)|(dpt:|dports?\s+[\d,:]*\b)' . $wp . '\b.*(DROP|REJECT)/', (string)$fw4);
            $findings[] = finding('data2g-ui-exposed','security', ($wl || $wBlocked) ? 'ok' : 'warn',
                ($wl || $wBlocked) ? "Data2G monitor page (port $wp) kept local" : "Data2G monitor page (port $wp) open to the network",
                "data2g-web serves its page (with a password, no TLS) on {$dst['monitor']['listen']}:$wp. Its Settings tab can change Data2G."
                . ($wl ? '' : ' The password would cross the network in the clear.'),
                ($wl || $wBlocked) ? '' : "sudo iptables -I INPUT -p tcp --dport $wp ! -i lo -j DROP && sudo netfilter-persistent save");
        }
    }
    // Data2G's calls reach RF Connections (parse-data2g-log.py, run by the VARA parser)
    if (!empty($pdo) && $dst['log_there']) {
        try {
            $t = (string)$pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                       AND TABLE_NAME = 'rf_connections' AND COLUMN_NAME = 'modem'")->fetchColumn();
            if (strpos($t, "'data2g'") === false) {
                $findings[] = finding('data2g-parser','logs','warn','Data2G calls not in RF Connections yet',
                    'The database upgrade that lets rf_connections hold Data2G rows has not run, so parse-data2g-log.py stops.',
                    'Run update.sh again, or: sudo mysql <db> < ' . $WEB_ROOT . '/sql/2026-11-15-rf-connections-data2g.sql');
            } else {
                $st = $pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, last_parsed_at, NOW()) FROM rf_parser_state WHERE log_file = ?');
                $st->execute([basename($dset['log'])]);
                $age = $st->fetchColumn();
                $n = (int)$pdo->query("SELECT COUNT(*) FROM rf_connections WHERE modem = 'data2g'")->fetchColumn();
                $ok = $age !== false && $age !== null && (int)$age <= 30;
                $findings[] = finding('data2g-parser','logs', $ok ? 'ok' : 'warn',
                    $ok ? 'Data2G calls read into RF Connections' : 'Data2G log not read into RF Connections',
                    $ok ? "$n Data2G call(s) in the table; last read {$age} min ago."
                        : ($age === false || $age === null ? 'parse-data2g-log.py has never finished a run.' : "Last read $age min ago.")
                          . ' The VARA parser runs it every 5 minutes; its line is in parse-vara-log.log.',
                    $ok ? '' : 'sudo -u www-data python3 ' . $WEB_ROOT . '/scripts/parse-data2g-log.py --dry-run');
            }
        } catch (Throwable $e) { /* database checks above report it */ }
    }
}

// v1.5.67: BBS mail store for mail apps (optional, tools/bbs-mail-setup.sh)
$bmConf = '/etc/dovecot/conf.d/99-bpq-dashboard.conf';
if (@file_exists($bmConf)) {
    $bmSetup = "sudo bash $WEB_ROOT/tools/bbs-mail-setup.sh";
    $dvUp = sh_quiet('systemctl is-active dovecot') === 'active';
    $tmUp = sh_quiet('systemctl is-active bbs-mail-sync.timer') === 'active';
    $bmSt = json_decode((string)@file_get_contents('/var/lib/bpq-dashboard/bbs-mail-sync.json'), true) ?: [];
    $lastRun = (int)($bmSt['last_run_ts'] ?? 0);
    $runAge = $lastRun ? (int)round((time() - $lastRun) / 60) : null;
    $okAll = $dvUp && $tmUp && $runAge !== null && $runAge <= 15 && empty($bmSt['last_error']);
    $secOk = @is_readable('/etc/bpq-dashboard/bbs-mail.php');   // v1.5.68: Email Client → Mail store
    $findings[] = finding('bbs-mail','application', $okAll ? 'ok' : 'warn',
        !$dvUp ? 'BBS mail store: Dovecot not running' : (!$tmUp ? 'BBS mail store: copier timer off'
            : (!empty($bmSt['last_error']) ? 'BBS mail store: last copy had an error' : ($okAll ? 'BBS mail store (IMAP) running' : 'BBS mail store: copier not running'))),
        'Dovecot ' . ($dvUp ? 'running' : 'stopped') . ', bbs-mail-sync.timer ' . ($tmUp ? 'on' : 'off')
        . ($runAge !== null ? ", last copy run $runAge min ago" : ', no copy run yet')
        . (!empty($bmSt['last_copy']) ? ", last message copied {$bmSt['last_copy']}" : '')
        . (!empty($bmSt['last_error']) ? '; error: ' . $bmSt['last_error'] : '')
        . ($secOk ? '; Email Client can read it' : "; the Email Client's Mail store view has no login yet (run $bmSetup again)") . '.',
        $okAll ? '' : (!$dvUp ? 'sudo systemctl restart dovecot; sudo journalctl -u dovecot -n 30'
                     : "$bmSetup --status; journalctl -u bbs-mail-sync -n 20"));
    // v1.5.70: kills waiting from the mail store's Trash (message-bodies.timer does them)
    if (!empty($pdo)) {
        try {
            $hasK = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_bbs_kills'")->fetchColumn();
            if ($hasK) {
                $old = (int)$pdo->query("SELECT COUNT(*) FROM bpq_bbs_kills WHERE done_at IS NULL AND queued_at < NOW() - INTERVAL 30 MINUTE")->fetchColumn();
                $err = (int)$pdo->query("SELECT COUNT(*) FROM bpq_bbs_kills WHERE result = 'error' AND done_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
                $ok7 = (int)$pdo->query("SELECT COUNT(*) FROM bpq_bbs_kills WHERE result IN ('killed','gone') AND done_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
                $findings[] = finding('bbs-mail-kills','application', ($old || $err) ? 'warn' : 'ok',
                    ($old || $err) ? 'Trash → BBS kills not going through' : 'Trash → BBS kills up to date',
                    "$ok7 killed (or already gone) in 7 days" . ($old ? "; $old waiting more than 30 minutes" : '') . ($err ? "; $err gave up after 3 tries" : '') . '.',
                    ($old || $err) ? 'Log Viewer → Message text job; sudo systemctl status message-bodies.timer' : '');
            }
        } catch (Throwable $e) { /* database checks above report it */ }
    }
    // Kept to what it should be: plain IMAP local, computer accounts off, password file private
    $bmText = (string)@file_get_contents($bmConf);
    $auth = (string)@file_get_contents('/etc/dovecot/conf.d/10-auth.conf');
    $pw = '/etc/dovecot/bpq-dashboard.passwd';
    $pwPerm = @file_exists($pw) ? (fileperms($pw) & 0777) : null;
    $issues = [];
    if (!preg_match('/inet_listener imap \{\s*(?:address|listen) = 127\.0\.0\.1/', $bmText)) $issues[] = 'plain IMAP (143) is not kept to this machine';
    if (preg_match('/^\s*!include auth-system\.conf\.ext/m', $auth)) $issues[] = "the computer's own accounts can log in to Dovecot";
    if ($pwPerm === null) $issues[] = 'no mail password file';
    elseif ($pwPerm & 0007) $issues[] = sprintf('the mail password file is readable by everyone (%o)', $pwPerm);
    if (!preg_match('/^ssl = required/m', $bmText)) $issues[] = 'encryption is not required';
    // v1.5.68: the Email Client's own login (Mail store view): private to the web server
    $sec = '/etc/bpq-dashboard/bbs-mail.php';
    if (@file_exists($sec) && (fileperms($sec) & 0007)) $issues[] = sprintf('%s is readable by everyone (%o)', $sec, fileperms($sec) & 0777);
    $findings[] = finding('bbs-mail-security','security', $issues ? 'warn' : 'ok',
        $issues ? 'BBS mail store settings changed' : 'BBS mail store: encrypted, LAN logins, one mail login',
        $issues ? ucfirst(implode('; ', $issues)) . '.'
                : 'IMAP over TLS on 993, plain IMAP on 127.0.0.1 only, logins from your own network only, computer accounts off, no outgoing mail.',
        $issues ? "Run $bmSetup again (it rewrites its settings)." : '');
}

// Shared nav: files present, and every page's ?v= matches the loader's NAV_VERSION
$navLoader = "$WEB_ROOT/bpq-nav-loader.js";
if (!file_exists("$WEB_ROOT/_nav.html") || !file_exists($navLoader)) {
    $miss = array_values(array_filter(['_nav.html', 'bpq-nav-loader.js'], fn($f) => !file_exists("$WEB_ROOT/$f")));
    $findings[] = finding('shared-nav','application','crit','Menu files missing: ' . implode(', ', $miss),
        'Pages open without the menu bar.', 'Copy them from the release package into ' . $WEB_ROOT . '/');
} else {
    $navVer = preg_match("/NAV_VERSION\s*=\s*'([^']+)'/", (string)@file_get_contents($navLoader), $m) ? $m[1] : '';
    $vers = [];
    foreach (array_merge(glob("$WEB_ROOT/*.html") ?: [], glob("$WEB_ROOT/admin/*.html") ?: []) as $page) {
        if (preg_match('/bpq-nav-loader\.js\?v=([A-Za-z0-9-]+)/', (string)@file_get_contents($page), $m)) $vers[$m[1]][] = str_replace("$WEB_ROOT/", '', $page);
    }
    $stale = array_diff_key($vers, [$navVer => 1]);
    if ($navVer !== '' && !$stale) {
        $findings[] = finding('nav-version','application','ok','Menu cache-bust up to date',
            count($vers[$navVer] ?? []) . " pages load bpq-nav-loader.js?v=$navVer, the loader's NAV_VERSION.");
    } else {
        $list = [];
        foreach ($stale as $v => $pages) $list[] = "?v=$v: " . count($pages) . ' page(s) (' . implode(', ', array_slice($pages, 0, 4)) . (count($pages) > 4 ? ', …' : '') . ')';
        $findings[] = finding('nav-version','application','warn','Pages load an old menu loader',
            "The loader's NAV_VERSION is " . ($navVer ?: '(not found)') . ', but ' . implode('; ', $list) . '. Browsers may keep using a cached older loader and menu on those pages.',
            "sudo sed -i -E 's|bpq-nav-loader\\.js\\?v=[A-Za-z0-9-]+|bpq-nav-loader.js?v=$navVer|' $WEB_ROOT/*.html $WEB_ROOT/admin/*.html");
    }
}

// SSL certificates — config.php 'ssl_domains', else the host this page was opened on
$sslDomains = (!empty($cfg['ssl_domains']) && is_array($cfg['ssl_domains'])) ? $cfg['ssl_domains']
            : (($host !== '' && !filter_var($host, FILTER_VALIDATE_IP) && $https) ? [$host] : []);
foreach ($sslDomains as $domain) {
    $domain = trim((string)$domain);
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,253}[a-zA-Z0-9]$/', $domain)) continue;
    // 2026-10-25: ask this server's own web server first (127.0.0.1 with the
    // name as SNI). Going out to the public address fails on most home
    // networks, whose routers don't loop a server's traffic back to itself.
    $certEnd = ''; $via = '';
    foreach (['127.0.0.1' => 'this server', $domain => 'its public address'] as $target => $label) {
        $cmd = 'echo | timeout 5 openssl s_client -connect ' . escapeshellarg("$target:443") . ' -servername ' . escapeshellarg($domain)
             . ' 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2';
        $certEnd = sh_quiet($cmd);
        if ($certEnd && strtotime($certEnd)) { $via = $label; break; }
    }
    if (!$certEnd || !strtotime($certEnd)) {
        $findings[] = finding("ssl-cert-$domain",'application','warn',"Certificate check failed for $domain",
            "No certificate for $domain from this server's port 443 or from $domain:443 within 5 seconds. If this name is served by another machine, list only this server's names in config.php ssl_domains.",
            "echo | openssl s_client -connect 127.0.0.1:443 -servername $domain | openssl x509 -noout -enddate");
        continue;
    }
    $days = (int)floor((strtotime($certEnd) - time()) / 86400);
    $findings[] = finding("ssl-cert-$domain",'application', $days < 14 ? 'crit' : ($days < 30 ? 'warn' : 'ok'),
        "$domain certificate expires in $days days", "Valid until $certEnd (certificate served by $via).",
        $days < 30 ? "Let's Encrypt renews at 30 days; if it hasn't: sudo certbot renew --cert-name $domain, and check systemctl list-timers certbot.timer" : '');
}

// ── Summary ───────────────────────────────────────────────────────────────────
$counts = ['ok' => 0, 'warn' => 0, 'crit' => 0, 'info' => 0];
foreach ($findings as $f) $counts[$f['status']] = ($counts[$f['status']] ?? 0) + 1;

$station = bpq_station();
echo json_encode([
    'findings'     => $findings,
    'summary'      => $counts,
    'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    'hostname'     => sh_quiet('hostname'),
    'uptime'       => sh_quiet('uptime -p'),
    'kernel'       => sh_quiet('uname -r'),
    // the config template's placeholders aren't shown
    'callsign'     => preg_match('/^YOURCALL/i', (string)($station['callsign'] ?? '')) ? '' : (string)($station['callsign'] ?? ''),
    'node'         => preg_match('/^YOURCALL/i', (string)($station['node'] ?? '')) ? '' : (string)($station['node'] ?? ''),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
