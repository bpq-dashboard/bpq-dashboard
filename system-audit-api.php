<?php
/**
 * system-audit-api.php — System health audit API for BPQSERVER
 * Version: 1.5.5
 *
 * Runs system checks across 6 categories and returns structured JSON
 * with findings and recommendations.
 *
 * Add to /etc/sudoers.d/www-data-audit:
 *   www-data ALL=(ALL) NOPASSWD: /bin/ss -tlnp
 *   www-data ALL=(ALL) NOPASSWD: /usr/bin/apt list --upgradable
 *   www-data ALL=(ALL) NOPASSWD: /bin/systemctl list-units --type=service --state=active
 *   www-data ALL=(ALL) NOPASSWD: /sbin/iptables -L -n
 *   www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status
 *   www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status sshd
 *   www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl -n 50 -p err
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Auth ──────────────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/bpqdash-config.php';
if (file_exists($configFile)) {
    $cfg = include $configFile;
    $bbsPassword = $cfg['bbs_password'] ?? $cfg['password'] ?? null;
} else {
    $bbsPassword = null;
}
$providedPassword = $_GET['password'] ?? $_SERVER['HTTP_X_BBS_PASSWORD'] ?? null;
if ($bbsPassword && $providedPassword !== $bbsPassword) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function sh(string $cmd): string {
    return trim(shell_exec($cmd . ' 2>&1') ?? '');
}

function finding(string $id, string $category, string $status, string $title,
                 string $detail, string $recommendation = ''): array {
    return compact('id','category','status','title','detail','recommendation');
}

$findings = [];

// ═══════════════════════════════════════════════════════
// 1. SECURITY POSTURE
// ═══════════════════════════════════════════════════════

// Firewall policy
$fw = sh('sudo /usr/sbin/iptables -L INPUT -n | head -3');
if (strpos($fw, 'policy DROP') !== false) {
    $findings[] = finding('fw-policy','security','ok','Firewall policy is DROP',
        'INPUT chain default policy is DROP — all unmatched traffic is rejected.');
} else {
    $findings[] = finding('fw-policy','security','warn','Firewall policy is not DROP',
        'INPUT chain policy is ACCEPT — all ports are open unless explicitly blocked.',
        'Set firewall default policy to DROP: sudo iptables -P INPUT DROP && sudo netfilter-persistent save');
}

// SSH on non-standard port
$sshPort = sh('sudo /bin/ss -tlnp | grep sshd | awk \'{print $4}\' | grep -o \':[0-9]*\' | head -1 | tr -d \':\'');
if ($sshPort && $sshPort !== '22') {
    $findings[] = finding('ssh-port','security','ok',"SSH on non-standard port $sshPort",
        "SSH is listening on port $sshPort rather than the default port 22, reducing automated scan exposure.");
} else {
    $findings[] = finding('ssh-port','security','warn','SSH on default port 22',
        'SSH is listening on port 22 — a high-value target for automated brute force scans.',
        'Change SSH port in /etc/ssh/sshd_config: Port 2222 (or another non-standard port)');
}

// Fail2ban
$f2b = sh('sudo /usr/bin/fail2ban-client status 2>/dev/null');
if (strpos($f2b, 'Jail list') !== false) {
    $banned = sh('sudo /usr/bin/fail2ban-client status sshd 2>/dev/null | grep "Currently banned" | grep -o "[0-9]*"');
    $findings[] = finding('fail2ban','security','ok','Fail2ban active',
        "Fail2ban is running. Currently $banned IPs banned on SSH jail.");
} else {
    $findings[] = finding('fail2ban','security','crit','Fail2ban not running',
        'Fail2ban is not active — brute force attacks will not be automatically blocked.',
        'sudo systemctl start fail2ban && sudo systemctl enable fail2ban');
}

// Webmin exposure
$webminBind = sh('sudo /bin/grep "^bind=" /etc/webmin/miniserv.conf');
if (strpos($webminBind, '10.0.') !== false || strpos($webminBind, '192.168.') !== false) {
    $findings[] = finding('webmin','security','ok','Webmin bound to LAN only',
        "Webmin is bound to $webminBind — not exposed to the internet.");
} elseif ($webminBind) {
    $findings[] = finding('webmin','security','warn','Webmin bind address check',
        "Webmin bind: $webminBind — verify this is LAN-only.",
        'Set bind=10.0.0.XXX in /etc/webmin/miniserv.conf and restart webmin.');
} else {
    $findings[] = finding('webmin','security','warn','Webmin may be internet-exposed',
        'Could not confirm Webmin bind address. Webmin on port 10000 may be accessible from internet.',
        'Add bind=<LAN-IP> to /etc/webmin/miniserv.conf');
}

// EOL PHP versions
foreach (['7.4','8.1'] as $ver) {
    $status = sh("systemctl is-active php$ver-fpm 2>/dev/null");
    if ($status === 'active') {
        $findings[] = finding("php-eol-$ver",'security','warn',"PHP $ver FPM is running (EOL)",
            "PHP $ver has reached end-of-life and no longer receives security patches.",
            "sudo systemctl stop php$ver-fpm && sudo systemctl disable php$ver-fpm");
    }
}

// Telnet
$xinetd = sh('systemctl is-active xinetd 2>/dev/null');
if ($xinetd === 'active') {
    $findings[] = finding('telnet','security','crit','xinetd/telnet is running',
        'xinetd is active — this may be exposing unencrypted telnet on port 23.',
        'sudo systemctl stop xinetd && sudo systemctl disable xinetd');
} else {
    $findings[] = finding('telnet','security','ok','xinetd/telnet is stopped',
        'xinetd is not running — telnet port 23 is not exposed.');
}

// ═══════════════════════════════════════════════════════
// 2. RUNNING SERVICES & RESOURCES
// ═══════════════════════════════════════════════════════

// Memory
$meminfo = file('/proc/meminfo', FILE_IGNORE_NEW_LINES);
$mem = [];
foreach ($meminfo as $line) {
    if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
        $mem[$m[1]] = (int)$m[2];
    }
}
$totalMB   = round(($mem['MemTotal'] ?? 0) / 1024);
$availMB   = round(($mem['MemAvailable'] ?? 0) / 1024);
$usedMB    = $totalMB - $availMB;
$usedPct   = $totalMB > 0 ? round($usedMB / $totalMB * 100) : 0;
$swapTotal = round(($mem['SwapTotal'] ?? 0) / 1024);
$swapFree  = round(($mem['SwapFree'] ?? 0) / 1024);
$swapUsed  = $swapTotal - $swapFree;

$memStatus = $usedPct > 85 ? 'crit' : ($usedPct > 70 ? 'warn' : 'ok');
$findings[] = finding('memory','resources',$memStatus,'Memory usage',
    "{$usedMB}MB used of {$totalMB}MB ({$usedPct}%). {$availMB}MB available.",
    $usedPct > 70 ? 'Identify top memory consumers: ps aux --sort=-%mem | head -10' : '');

if ($swapUsed > 500) {
    $findings[] = finding('swap','resources','warn',"Swap usage: {$swapUsed}MB",
        "{$swapUsed}MB of {$swapTotal}MB swap in use. With {$totalMB}MB RAM this suggests a memory leak.",
        'Check top memory consumers. A browser (Firefox) restart after long uptime usually frees swap.');
} else {
    $findings[] = finding('swap','resources','ok',"Swap usage: {$swapUsed}MB",
        "Swap usage is low ({$swapUsed}MB). System is not under memory pressure.");
}

// CPU load
$load = sys_getloadavg();
$cpuCount = (int)sh('nproc');
$loadPct = $cpuCount > 0 ? round($load[0] / $cpuCount * 100) : 0;
$loadStatus = $loadPct > 80 ? 'crit' : ($loadPct > 50 ? 'warn' : 'ok');
$findings[] = finding('cpu','resources',$loadStatus,'CPU load average',
    "Load: {$load[0]} / {$load[1]} / {$load[2]} on {$cpuCount} cores ({$loadPct}% of capacity).",
    $loadPct > 50 ? 'Identify CPU consumers: ps aux --sort=-%cpu | head -10' : '');

// Disk usage
$disk = disk_free_space('/');
$diskTotal = disk_total_space('/');
$diskUsed = $diskTotal - $disk;
$diskPct = round($diskUsed / $diskTotal * 100);
$diskStatus = $diskPct > 85 ? 'crit' : ($diskPct > 70 ? 'warn' : 'ok');
$diskUsedGB = round($diskUsed / 1073741824);
$diskTotalGB = round($diskTotal / 1073741824);
$findings[] = finding('disk','resources',$diskStatus,'Disk usage',
    "{$diskUsedGB}GB used of {$diskTotalGB}GB ({$diskPct}%). " . round($disk/1073741824) . "GB free.",
    $diskPct > 70 ? 'Check large directories: sudo du -sh /var/log/* | sort -rh | head -10' : '');

// fwupd
$fwupd = sh('systemctl is-active fwupd 2>/dev/null');
if ($fwupd === 'active') {
    $findings[] = finding('fwupd','resources','warn','fwupd is running (~200MB RAM)',
        'Firmware update daemon is running. Not needed on a headless server — wastes ~200MB RAM.',
        'sudo systemctl stop fwupd && sudo systemctl disable fwupd && sudo systemctl mask fwupd');
} else {
    $findings[] = finding('fwupd','resources','ok','fwupd is stopped',
        'Firmware update daemon is not running — RAM is not being wasted.');
}

// Conky
$conkyProcs = sh('pgrep -c conky 2>/dev/null');
if ((int)$conkyProcs > 0) {
    $conkyCmd = sh('ps aux | grep "conky.*pause=1" | grep -v grep | head -1');
    if (strpos($conkyCmd, 'pause=1') !== false) {
        $findings[] = finding('conky','resources','warn',"Conky running with 1-second update interval",
            "$conkyProcs conky instance(s) running with --pause=1. Each instance uses ~3.4% CPU continuously.",
            'Increase update_interval to 10 in ~/.conkyrc to reduce CPU usage from ~7% to ~0.7%.');
    }
}

// ═══════════════════════════════════════════════════════
// 3. NGINX & PHP HEALTH
// ═══════════════════════════════════════════════════════

// nginx status
$nginx = sh('systemctl is-active nginx 2>/dev/null');
if ($nginx === 'active') {
    $findings[] = finding('nginx','nginx','ok','nginx is running',
        'nginx web server is active and serving requests.');
} else {
    $findings[] = finding('nginx','nginx','crit','nginx is not running',
        'nginx is not active — the web server is down.',
        'sudo systemctl start nginx && sudo systemctl enable nginx');
}

// nginx config test
$nginxTest = trim(shell_exec('sudo /usr/sbin/nginx -t 2>&1'));
if (strpos($nginxTest, 'successful') !== false) {
    $findings[] = finding('nginx-config','nginx','ok','nginx configuration is valid',
        'nginx -t passed — no syntax errors in nginx configuration.');
} else {
    $findings[] = finding('nginx-config','nginx','crit','nginx configuration has errors',
        "nginx -t output: $nginxTest",
        'Fix nginx config errors before reloading: sudo nginx -t');
}

// nginx error log recent errors (excluding duplicate session errors which are now fixed)
$nginxErrors = sh('tail -100 /var/log/nginx/error.log 2>/dev/null | grep -v "bpq_insert_session" | grep "\[error\]\|\[crit\]" | wc -l');
if ((int)$nginxErrors > 10) {
    $sample = sh('tail -100 /var/log/nginx/error.log 2>/dev/null | grep -v "bpq_insert_session" | grep "\[error\]\|\[crit\]" | tail -3');
    $findings[] = finding('nginx-errors','nginx','warn',"$nginxErrors recent nginx errors",
        "Sample: $sample",
        'Review nginx error log: sudo tail -50 /var/log/nginx/error.log');
} else {
    $findings[] = finding('nginx-errors','nginx','ok','nginx error log is clean',
        "Only $nginxErrors non-session errors in last 100 log lines.");
}

// PHP-FPM 8.3
$php83 = sh('systemctl is-active php8.3-fpm 2>/dev/null');
if ($php83 === 'active') {
    $findings[] = finding('php83','nginx','ok','PHP 8.3 FPM is running',
        'PHP 8.3 FastCGI Process Manager is active.');
} else {
    $findings[] = finding('php83','nginx','crit','PHP 8.3 FPM is not running',
        'PHP 8.3-fpm is not active — PHP pages will return 502 Bad Gateway.',
        'sudo systemctl start php8.3-fpm && sudo systemctl enable php8.3-fpm');
}

// ═══════════════════════════════════════════════════════
// 4. MYSQL / MARIADB
// ═══════════════════════════════════════════════════════

// MariaDB running
$mariadb = sh('systemctl is-active mariadb 2>/dev/null');
if ($mariadb === 'active') {
    $findings[] = finding('mariadb','database','ok','MariaDB is running',
        'MariaDB database server is active.');
} else {
    $findings[] = finding('mariadb','database','crit','MariaDB is not running',
        'MariaDB is not active — all database-dependent features will fail.',
        'sudo systemctl start mariadb && sudo systemctl enable mariadb');
}

// MariaDB localhost only
$mariadbBind = sh('grep -E "^bind-address|^bind_address" /etc/mysql/mariadb.conf.d/50-server.cnf 2>/dev/null | head -1');
if (strpos($mariadbBind, '127.0.0.1') !== false || strpos($mariadbBind, 'localhost') !== false) {
    $findings[] = finding('mariadb-bind','database','ok','MariaDB bound to localhost',
        "MariaDB bind-address: $mariadbBind — not exposed to network.");
} else {
    $port3306 = sh('ss -tlnp | grep 3306');
    if (strpos($port3306, '0.0.0.0') !== false) {
        $findings[] = finding('mariadb-bind','database','warn','MariaDB may be network-accessible',
            'MariaDB port 3306 appears to be listening on all interfaces.',
            'Set bind-address = 127.0.0.1 in /etc/mysql/mariadb.conf.d/50-server.cnf');
    } else {
        $findings[] = finding('mariadb-bind','database','ok','MariaDB port not exposed',
            'MariaDB port 3306 is not listening on external interfaces.');
    }
}

// InnoDB buffer pool
$bufPool = sh('mysql -u root -e "SHOW VARIABLES LIKE \'innodb_buffer_pool_size\';" 2>/dev/null | grep -o "[0-9]*" | tail -1');
if ($bufPool) {
    $bufMB = round((int)$bufPool / 1048576);
    if ($bufMB < 256) {
        $findings[] = finding('innodb-buffer','database','warn',"InnoDB buffer pool: {$bufMB}MB",
            "InnoDB buffer pool is {$bufMB}MB. With {$totalMB}MB RAM, a larger pool improves query performance.",
            "Add innodb_buffer_pool_size = 256M to /etc/mysql/mariadb.conf.d/50-server.cnf and restart MariaDB.");
    } else {
        $findings[] = finding('innodb-buffer','database','ok',"InnoDB buffer pool: {$bufMB}MB",
            "InnoDB buffer pool is well-sized at {$bufMB}MB.");
    }
}

// Slow queries
$slowQ = sh('mysql -u root -e "SHOW STATUS LIKE \'Slow_queries\';" 2>/dev/null | grep -o "[0-9]*" | tail -1');
if ((int)$slowQ > 100) {
    $findings[] = finding('slow-queries','database','warn',"$slowQ slow queries recorded",
        "MariaDB has logged $slowQ slow queries. This may indicate missing indexes or inefficient queries.",
        'Enable slow query log: slow_query_log=1, slow_query_log_file=/var/log/mysql/slow.log in my.cnf');
} else {
    $findings[] = finding('slow-queries','database','ok',"Slow queries: $slowQ",
        'No significant slow query count recorded.');
}

// ═══════════════════════════════════════════════════════
// 5. SYSTEM LOGS & UPDATES
// ═══════════════════════════════════════════════════════

// Pending security updates
$updates = sh('sudo /usr/bin/apt list --upgradable 2>/dev/null | grep -i "security\|oldstable" | wc -l');
if ((int)$updates > 0) {
    $updateList = sh('sudo /usr/bin/apt list --upgradable 2>/dev/null | grep -i "security\|oldstable" | head -5');
    $findings[] = finding('updates','logs',(int)$updates > 5 ? 'crit' : 'warn',
        "$updates pending security update(s)",
        "Packages needing security updates:\n$updateList",
        'sudo apt update && sudo apt upgrade -y');
} else {
    $findings[] = finding('updates','logs','ok','No pending security updates',
        'System is fully up to date on security patches.');
}

// /var/log disk usage
$logSize = sh('du -s /var/log 2>/dev/null | cut -f1');
$logMB = round((int)$logSize / 1024);
if ($logMB > 5000) {
    $topLogs = sh('du -sh /var/log/* 2>/dev/null | sort -rh | head -5');
    $findings[] = finding('log-size','logs','warn',"/var/log is {$logMB}MB",
        "Log directory is large. Top consumers:\n$topLogs",
        'Check for unrotated logs. Verify /etc/logrotate.d/bpqserver is in place.');
} else {
    $findings[] = finding('log-size','logs','ok',"/var/log is {$logMB}MB",
        'Log directory size is reasonable.');
}

// Auth log recent failures
$authFails = sh('grep "Failed password\|Invalid user" /var/log/auth.log 2>/dev/null | wc -l');
if ((int)$authFails > 100) {
    $findings[] = finding('auth-fails','logs','warn',"$authFails SSH auth failures in auth.log",
        "High number of authentication failures — brute force activity detected.",
        'Verify fail2ban is catching these. Consider GeoIP blocking for SSH.');
} else {
    $findings[] = finding('auth-fails','logs','ok',"$authFails SSH auth failures",
        'Auth failure count is within normal range.');
}

// Logrotate bpqserver config
$lrConfig = file_exists('/etc/logrotate.d/bpqserver');
if ($lrConfig) {
    $findings[] = finding('logrotate','logs','ok','BPQSERVER logrotate config present',
        'Custom log rotation is configured at /etc/logrotate.d/bpqserver.');
} else {
    $findings[] = finding('logrotate','logs','warn','BPQSERVER logrotate config missing',
        'No custom logrotate config found. BPQDash/BPQ/VARA logs may grow unchecked.',
        'Deploy /etc/logrotate.d/bpqserver — see BPQ Dashboard maintenance reference.');
}

// ═══════════════════════════════════════════════════════
// 6. APPLICATION SPECIFIC
// ═══════════════════════════════════════════════════════

// BPQ / linbpq
$bpq = sh('systemctl is-active bpq 2>/dev/null');
if ($bpq === 'active') {
    $findings[] = finding('bpq','application','ok','LinBPQ is running',
        'BPQ packet radio node (linbpq) is active.');
} else {
    $findings[] = finding('bpq','application','warn','LinBPQ is not running',
        'LinBPQ is not active — packet radio operations are offline.',
        'sudo systemctl start bpq');
}

// PAT Winlink
$pat = sh('systemctl is-active pat@YOURCALL 2>/dev/null');
if ($pat === 'active') {
    $findings[] = finding('pat','application','ok','PAT Winlink is running',
        'PAT Winlink client (YOURCALL) is active on port 8080.');
} else {
    $findings[] = finding('pat','application','warn','PAT Winlink is not running',
        'PAT Winlink is not active.',
        'sudo systemctl start pat@YOURCALL');
}

// VARA validator
$varaVal = sh('systemctl is-active vara-validator 2>/dev/null');
if ($varaVal === 'active') {
    $findings[] = finding('vara-validator','application','ok','VARA Callsign Validator is running',
        'VARA callsign validator proxy is active on ports 9025/9026.');
} else {
    $findings[] = finding('vara-validator','application','warn','VARA Callsign Validator is not running',
        'VARA validator proxy is not active — BPQ may be connecting directly to VARA without callsign filtering.',
        'sudo systemctl start vara-validator');
}

// VARA logger
$varaLog = sh('systemctl is-active vara-logger 2>/dev/null');
if ($varaLog === 'active') {
    $findings[] = finding('vara-logger','application','ok','VARA Logger is running',
        'VARA session logger service is active.');
} else {
    $findings[] = finding('vara-logger','application','warn','VARA Logger is not running',
        'VARA logger is not active — session data may not be recorded.',
        'sudo systemctl start vara-logger');
}

// NWS monitor
$nwsMon = sh('systemctl is-active nws-monitor 2>/dev/null');
if ($nwsMon === 'active') {
    $findings[] = finding('nws-monitor','application','ok','NWS Weather Monitor is running',
        'NWS weather alert monitor service is active.');
} else {
    $findings[] = finding('nws-monitor','application','warn','NWS Weather Monitor is not running',
        'NWS monitor is not active — weather alerts will not be processed.',
        'sudo systemctl start nws-monitor');
}

// BPQDash web root permissions
$webRoot = '/var/www/bpqdash';
if (is_dir($webRoot)) {
    $owner = sh("stat -c '%U' $webRoot");
    if ($owner === 'www-data') {
        $findings[] = finding('webroot-perms','application','ok','Web root owned by www-data',
            "BPQDash web root ($webRoot) is correctly owned by www-data.");
    } else {
        $findings[] = finding('webroot-perms','application','warn',"Web root owned by $owner",
            "BPQDash web root is owned by $owner instead of www-data — may cause permission errors.",
            "sudo chown -R www-data:www-data $webRoot");
    }
}

// SSL cert expiry
$certExpiry = sh('echo | openssl s_client -connect your-domain.com:443 -servername your-domain.com 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2');
if ($certExpiry) {
    $expiryTs = strtotime($certExpiry);
    $daysLeft = round(($expiryTs - time()) / 86400);
    $certStatus = $daysLeft < 14 ? 'crit' : ($daysLeft < 30 ? 'warn' : 'ok');
    $findings[] = finding('ssl-cert','application',$certStatus,"SSL certificate expires in {$daysLeft} days",
        "Certificate valid until: $certExpiry",
        $daysLeft < 30 ? 'sudo certbot renew --force-renewal' : '');
}

// ── Summary counts ────────────────────────────────────────────────────────────
$counts = ['ok' => 0, 'warn' => 0, 'crit' => 0];
foreach ($findings as $f) {
    $counts[$f['status']] = ($counts[$f['status']] ?? 0) + 1;
}

// ── Output ────────────────────────────────────────────────────────────────────
echo json_encode([
    'findings'     => $findings,
    'summary'      => $counts,
    'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    'hostname'     => sh('hostname'),
    'uptime'       => sh('uptime -p'),
    'kernel'       => sh('uname -r'),
], JSON_PRETTY_PRINT);
