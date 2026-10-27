<?php
/**
 * firewall-api.php — Firewall status API
 * Version: 2.0.0 (2026-10-25)
 *
 * Returns the firewall rules, the ports this server listens on and whether
 * each is reachable from the internet, plus fail2ban jails, as JSON.
 *
 * 2026-10-25:
 *  - Port list = what actually listens (ss) + what the rules mention, with
 *    the program behind each port. "Localhost" now means bound to 127.0.0.1
 *    (it was never set before; the filter showed IP-restricted ports).
 *  - Reachability follows jumps (ufw, fail2ban, custom chains) instead of
 *    reading INPUT only, and LAN = every private range (10/8, 172.16/12,
 *    192.168/16, 100.64/10, IPv6 ULA / link-local), not just 10.0.0.x.
 *  - IPv6: ip6tables policy and the ports open over IPv6 when the server has
 *    a public IPv6 address.
 *  - Port labels from config.php / bpq32.cfg; the station-specific list
 *    (Wine, PAT, XRDP, CUPS …) is gone. Port ranges shown in full.
 *  - fail2ban: totals and recent bans across all jails; the fail2ban-client
 *    path matches the sudoers file (/usr/bin).
 *
 * Needs /etc/sudoers.d/www-data-tools (copy of www-data-tools.sudoers).
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/web-login.php';
bpq_require_web_login('Firewall Status');
require_once dirname(__DIR__) . '/includes/admin-firewall.php';
$cfg = bpq_config_all();

function sudo_run(string $cmd): array {
    $out = (string)shell_exec('sudo -n ' . $cmd . ' 2>&1');
    $refused = preg_match('/^sudo: |a password is required|not allowed to execute|may not run sudo/m', $out);
    return [!$refused, $out];
}

// ── Port labels: well-known services + this station's own ports ───────────
$LABELS = [
    21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP',
    110 => 'POP3', 119 => 'NNTP', 139 => 'Samba NetBIOS', 143 => 'IMAP', 443 => 'HTTPS',
    445 => 'Samba SMB', 587 => 'SMTP submission', 631 => 'CUPS printing', 993 => 'IMAPS',
    3306 => 'MariaDB / MySQL', 3389 => 'RDP', 5432 => 'PostgreSQL',
    5900 => 'VNC', 5901 => 'VNC', 5902 => 'VNC', 5903 => 'VNC', 10000 => 'Webmin',
    8765 => 'bpq-telnet WebSocket (dashboard)', 8767 => 'bpq-vara WebSocket (dashboard)',
    8300 => 'VARA HF command', 8301 => 'VARA HF data',
    9025 => 'VARA validator command', 9026 => 'VARA validator data',
];
$flrigPort = (int)($cfg['radio']['flrig_port'] ?? 12345);
$LABELS[$flrigPort] = 'flrig (radio control)';
$LABELS[(int)($cfg['bbs']['port'] ?? 8010)] = 'BPQ telnet';
$bpqCfg = '';
foreach (array_filter([!empty($cfg['paths']['linbpq']) ? rtrim($cfg['paths']['linbpq'], '/') . '/bpq32.cfg' : null,
                       '/opt/linbpq/bpq32.cfg', '/home/linbpq/bpq32.cfg', '/etc/linbpq/bpq32.cfg']) as $c) {
    if (is_readable($c)) { $bpqCfg = (string)@file_get_contents($c); break; }
}
foreach (['HTTPPORT' => 'BPQ web management', 'TCPPORT' => 'BPQ telnet', 'FBBPORT' => 'BPQ FBB (YAPP / forwarding)'] as $k => $lab) {
    if (preg_match_all('/^\s*' . $k . '\s*=\s*([\d, ]+)/mi', $bpqCfg, $mm)) {
        foreach ($mm[1] as $list) foreach (preg_split('/[\s,]+/', trim($list)) as $p) if ($p !== '') $LABELS[(int)$p] = $lab;
    } elseif ($bpqCfg === '') {
        $LABELS += [8008 => 'BPQ web management (default)', 8011 => 'BPQ FBB (default)'];
    }
}

// ── Listening sockets, with the program name when sudo allows -p ──────────
[$ssok, $ss] = sudo_run('/usr/bin/ss -tlnp');
if (!$ssok) $ss = (string)shell_exec('ss -tln 2>/dev/null');
$listening = [];   // port => ['binds'=>[], 'procs'=>[]]
foreach (explode("\n", $ss) as $l) {
    $p = preg_split('/\s+/', trim($l));
    if (count($p) < 4 || $p[0] !== 'LISTEN') continue;
    if (!preg_match('/^(.*):(\d+)$/', $p[3], $m)) continue;
    $port = (int)$m[2];
    $bind = preg_replace('/%.*$/', '', trim($m[1], '[]'));
    $listening[$port]['binds'][$bind] = true;
    if (preg_match_all('/\("([^"]+)"/', $l, $pm)) foreach ($pm[1] as $proc) $listening[$port]['procs'][$proc] = true;
}

// ── Firewall: IPv4 and IPv6, all chains ───────────────────────────────────
[$ok4, $raw4] = sudo_run('/usr/sbin/iptables -L -n --line-numbers -v');
$chains4 = $ok4 ? bpq_fw_parse_all($raw4) : [];
$policy4 = $chains4['INPUT']['policy'] ?? 'UNKNOWN';

$globalV6 = false;
foreach (@file('/proc/net/if_inet6', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    $p = preg_split('/\s+/', trim($l));
    if (count($p) >= 6 && $p[3] === '00' && $p[5] !== 'lo' && !preg_match('/^f[cd]/', $p[0])) { $globalV6 = true; break; }
}
[$ok6, $raw6] = sudo_run('/usr/sbin/ip6tables -L -n --line-numbers -v');
$chains6 = $ok6 ? bpq_fw_parse_all($raw6) : [];

// Rules shown on the page: INPUT, plus the rules of chains INPUT jumps to
// (ufw-user-input holds ufw's "allow" rules), one level deep.
$rules = [];
foreach (($chains4['INPUT']['rules'] ?? []) as $r) {
    $r['chain'] = 'INPUT';
    $rules[] = $r;
}
foreach ($chains4 as $name => $ch) {
    if ($name === 'INPUT' || !preg_match('/^(ufw-user-input|ufw-user-limit-accept)$/', $name)) continue;
    foreach ($ch['rules'] as $r) { $r['chain'] = $name; $rules[] = $r; }
}
foreach ($rules as &$r) {
    if ($r['target'] === 'ACCEPT') {
        $r['category'] = bpq_fw_is_any($r['source']) ? 'public-allow' : (bpq_fw_is_lan($r['source']) || bpq_fw_is_loopback($r['source']) ? 'lan-allow' : 'ip-allow');
        if ($r['in'] === 'lo') $r['category'] = 'loopback';
    } elseif (in_array($r['target'], ['DROP', 'REJECT'], true)) {
        $r['category'] = 'block';
    } elseif (strpos($r['target'], 'f2b-') === 0) {
        $r['category'] = 'fail2ban';
    } else {
        $r['category'] = 'other';
    }
    $first = $r['ports'][0][0] ?? null;
    $r['label'] = $first !== null && count($r['ports']) === 1 && $r['ports'][0][0] === $r['ports'][0][1] ? ($LABELS[$first] ?? null) : null;
}
unset($r);

// ── Port summary: listening ports + ports named in ACCEPT rules ───────────
$ports = array_keys($listening);
foreach ($rules as $r) {
    if ($r['target'] !== 'ACCEPT') continue;
    foreach ($r['ports'] as [$a, $b]) if ($b - $a < 50) for ($i = $a; $i <= $b; $i++) $ports[] = $i;
}
$ports = array_values(array_unique($ports));
sort($ports);

$summary = [];
foreach ($ports as $port) {
    $L = $listening[$port] ?? null;
    $binds = $L ? array_keys($L['binds']) : [];
    $onlyLoop = $binds && !array_filter($binds, fn($b) => !preg_match('/^(127\.|::1$|::ffff:127\.)/', $b));
    $allowed = [];
    foreach ($rules as $r) {
        if ($r['target'] !== 'ACCEPT' || $r['in'] === 'lo' || !$r['ports']) continue;
        foreach ($r['ports'] as [$a, $b]) if ($port >= $a && $port <= $b) {
            $allowed[] = bpq_fw_is_any($r['source']) ? 'Internet (all)' : (bpq_fw_is_lan($r['source']) ? "LAN ({$r['source']})" : $r['source']);
        }
    }
    $pub4 = $chains4 ? bpq_fw_port_public($chains4, $port) : null;
    $pub6 = ($globalV6 && $chains6) ? bpq_fw_port_public($chains6, $port) : null;
    if ($L && $onlyLoop)                        $status = 'localhost';
    elseif ($pub4 === true || $pub6 === true)   $status = 'public';
    elseif ($pub4 === null && !$chains4)        $status = 'unknown';
    elseif (array_filter($allowed, fn($a) => strpos($a, 'LAN') === 0)) $status = 'lan-only';
    elseif ($allowed)                           $status = 'ip-restricted';
    else                                        $status = 'blocked';
    $summary[] = [
        'port'      => $port,
        'label'     => $LABELS[$port] ?? ($L && !empty($L['procs']) ? implode(', ', array_keys($L['procs'])) : "Port $port"),
        'process'   => $L ? implode(', ', array_keys($L['procs'] ?? [])) : '',
        'binds'     => $binds,
        'listening' => (bool)$L,
        'allowed'   => array_values(array_unique($allowed)),
        'status'    => $status,
        'public_v4' => $pub4,
        'public_v6' => $pub6,
    ];
}

// ── fail2ban ──────────────────────────────────────────────────────────────
$f2b = ['installed' => trim((string)shell_exec('command -v fail2ban-client 2>/dev/null')) !== '',
        'readable' => false, 'jails' => [], 'jail_summary' => [], 'total_banned' => 0,
        'sshd_banned' => 0, 'banned_count' => 0, 'recent_banned' => []];
if ($f2b['installed']) {
    [$fok, $fs] = sudo_run('/usr/bin/fail2ban-client status');
    if ($fok && preg_match('/Jail list:\s*(.*)/i', $fs, $m)) {
        $f2b['readable'] = true;
        $f2b['jails'] = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
        foreach ($f2b['jails'] as $jail) {
            $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $jail);
            if ($safe === '') continue;
            [, $jo] = sudo_run('/usr/bin/fail2ban-client status ' . $safe);
            $cur = preg_match('/Currently banned:\s*(\d+)/i', $jo, $mm) ? (int)$mm[1] : 0;
            $tot = preg_match('/Total banned:\s*(\d+)/i', $jo, $mm) ? (int)$mm[1] : 0;
            $f2b['jail_summary'][$jail] = ['banned' => $cur, 'total' => $tot];
            $f2b['total_banned'] += $cur;
            if (preg_match('/Banned IP list:\s*(.*)/i', $jo, $mm)) {
                foreach (array_filter(preg_split('/\s+/', trim($mm[1]))) as $ip) $f2b['recent_banned'][] = $ip;
            }
            if ($jail === 'sshd') { $f2b['sshd_banned'] = $cur; $f2b['banned_count'] = $tot; }
        }
        $f2b['recent_banned'] = array_slice($f2b['recent_banned'], -10);
    }
}

echo json_encode([
    'policy'        => $policy4,
    'readable'      => (bool)$chains4,
    'ufw'           => bpq_fw_is_ufw($chains4),
    'rules'         => $rules,
    'port_summary'  => $summary,
    'ss_processes'  => $ssok,
    'ipv6'          => [
        'has_global' => $globalV6,
        'readable'   => (bool)$chains6,
        'policy'     => $chains6['INPUT']['policy'] ?? 'UNKNOWN',
        'public_ports' => array_values(array_map(fn($s) => $s['port'], array_filter($summary, fn($s) => $s['public_v6'] === true && $s['listening']))),
    ],
    'fail2ban'      => $f2b,
    'sudo_hint'     => 'sudo cp ' . dirname(__DIR__) . '/www-data-tools.sudoers /etc/sudoers.d/www-data-tools && sudo chmod 440 /etc/sudoers.d/www-data-tools && sudo visudo -c',
    'generated_at'  => gmdate('Y-m-d H:i:s') . ' UTC',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
