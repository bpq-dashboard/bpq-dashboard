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
        ($fw4policy ? 'Station ports open to the internet' : 'Station ports listen on all interfaces'),
        'Listening on all interfaces' . ($fw4policy ? ' with no IPv4 firewall rule stopping them' : ' (firewall not readable, so they may be reachable)') . ': ' . implode('; ', $exposed) . '.',
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
    foreach (explode("\n", $ssOut) as $l) if (strpos($l, '"sshd"') !== false && preg_match('/:(\d+)\s/', $l, $m)) $sshPorts[(int)$m[1]] = true;
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
    $ignore = '/rename\(.*cache.*\.tmp|access forbidden by rule|limiting requests/';
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
foreach (['dashboard.log','bbs-messages.log','message-bodies.log','nws-auto-post.log','bpq-telnet-daemon.log','bpq-vara-daemon.log','prop-scheduler.log','storm-monitor.log','connect-watchdog.log'] as $f) {
    if (file_exists("$LOG_DIR/$f")) $own[] = "$LOG_DIR/$f";
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
$bpqPids = sh_quiet('pgrep -x linbpq');
if ($bpqPids !== '') {
    $findings[] = finding('linbpq','application','ok','LinBPQ is running', 'linbpq process ' . str_replace("\n", ', ', $bpqPids) . '.');
} else {
    $findings[] = finding('linbpq','application','crit','LinBPQ is not running',
        'No linbpq process: no BBS, no RF, no forwarding, and most pages show errors.',
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
foreach ($daemons as [$u, $what, $hb, $maxAge, $optional]) {
    [$inst, $act, $en] = unit_state("$u.service");
    if (!$inst) {
        $findings[] = finding("svc-$u",'application','info',"$u not installed",
            "No $u.service, so " . ($optional ? "$what is not in use (optional)." : "$what won't work. The installer asks about it in step 13; skip this if you don't use that page."),
            "sudo sed 's|/var/www/bpq-dashboard|$WEB_ROOT|g' $WEB_ROOT/$u.service | sudo tee /etc/systemd/system/$u.service >/dev/null && sudo systemctl daemon-reload && sudo systemctl enable --now $u");
        continue;
    }
    if ($act !== 'active') {
        $findings[] = finding("svc-$u",'application', ($optional && $en !== 'enabled') ? 'info' : 'crit', "$u is $act",
            "$u.service is installed but $act ($en), so $what " . (($optional && $en !== 'enabled') ? 'is switched off.' : 'is down.'),
            "sudo journalctl -u $u -n 30 --no-pager; sudo systemctl enable --now $u");
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

// flrig (Rig Status, RF Power Monitor)
if ($radioOn) {
    $fh = (string)($cfg['radio']['flrig_host'] ?? '127.0.0.1');
    $fp = (int)($cfg['radio']['flrig_port'] ?? 12345);
    $s = @fsockopen($fh, $fp, $en_, $es_, 2);
    if ($s) { fclose($s);
        $findings[] = finding('flrig','application','ok',"flrig answers at $fh:$fp", 'Rig Status and RF Power Monitor can read the radio.');
    } else {
        $findings[] = finding('flrig','application','warn',"flrig not reachable at $fh:$fp",
            'Rig Status and RF Power Monitor show no readings. Either flrig isn\'t running or config.php radio.flrig_host / flrig_port is wrong.',
            "pgrep -af flrig; sudo systemctl status flrig. No radio on this station? Set 'radio' => ['enabled' => false] in config.php.");
    }
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
foreach (['cache', 'cache/aprs', 'cache/chat-sessions', 'cache/telnet-sessions', 'data', 'data/stations'] as $d) {
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
