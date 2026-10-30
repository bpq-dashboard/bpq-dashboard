<?php
/**
 * BPQ Dashboard v1.5.15 — Post-Installation Check
 * Checks every component installed by bpq-dashboard-install.sh
 * Access: https://your.domain/install-check.php  (behind the admin login)
 *
 * 2026-10-05: no more ?pass=bpqcheck. That password was published in the
 * docs, so it protected nothing. The page now runs only when the web server
 * checked the admin login (same rule as the Email Client APIs, see
 * includes/web-login.php), or when config.php says
 * 'require_web_login' => false.
 */

// ── Auth ───────────────────────────────────────────────────────────
if (!is_readable(__DIR__ . '/includes/web-login.php')) {
    showLogin('includes/web-login.php is missing, so this page cannot check the admin login. Copy the includes/ folder from the release (or re-run install.sh).');
    exit;
}
require_once __DIR__ . '/includes/web-login.php';
if (!bpq_web_login_ok(false)) {
    showLogin('This page shows paths, versions and configuration, so it needs the dashboard admin login, '
            . "and the web server didn't pass one to PHP. nginx: inside  location = /install-check.php { auth_basic … }  add  "
            . 'fastcgi_param BPQ_WEB_LOGIN $remote_user;  before the fastcgi include, then  sudo nginx -t && sudo systemctl reload nginx. '
            . 'Apache: protect install-check.php with AuthType Basic. Or re-run install.sh.');
    exit;
}

// ── State ──────────────────────────────────────────────────────────
$BASE = __DIR__;
$results = []; $pass = $fail = $warn = 0;

function addResult($cat,$name,$status,$detail='',$fix='') {
    global $pass,$fail,$warn,$results;
    if ($status==='pass') $pass++;
    elseif ($status==='warn') $warn++;
    else $fail++;
    $results[] = compact('cat','name','status','detail','fix');
}
function chkPass($c,$n,$d='',$f='') { addResult($c,$n,'pass',$d,$f); }
function chkFail($c,$n,$d='',$f='') { addResult($c,$n,'fail',$d,$f); }
function chkWarn($c,$n,$d='',$f='') { addResult($c,$n,'warn',$d,$f); }
function chk($c,$n,$test,$d='',$f='',$w=false) {
    if ($test===true) chkPass($c,$n,$d,$f);
    elseif ($w)       chkWarn($c,$n,$d,$f);
    else              chkFail($c,$n,$d,$f);
}

function tcpOpen($h,$p,$t=5) {
    $s = @fsockopen($h,intval($p),$e,$m,$t);
    if ($s) { fclose($s); return true; } return false;
}
function fileOk($p) { return file_exists($p) && is_readable($p); }
function dirWrite($p) { return is_dir($p) && is_writable($p); }
function hbAge($f) {
    if (!fileOk($f)) return null;
    $d = @json_decode(file_get_contents($f),true);
    return isset($d['ts']) ? time()-intval($d['ts']) : null;
}
function hbData($f) {
    if (!fileOk($f)) return [];
    return @json_decode(file_get_contents($f),true) ?? [];
}

// ── Load config ────────────────────────────────────────────────────
$cfg = [];
if (fileOk("$BASE/config.php")) {
    $cfg = @include("$BASE/config.php");
    if (!is_array($cfg)) $cfg = [];
}

// ═══════════════════════════════════════════════════════════════════
// 1. PHP ENVIRONMENT
// ═══════════════════════════════════════════════════════════════════
$phpver = phpversion();
chk('PHP Environment','PHP ≥ 8.0', version_compare($phpver,'8.0','>='),
    "PHP $phpver", 'sudo apt install php8.3-fpm');

// Detect active FPM. 2026-10-30: was a fixed 8.4…8.1 list, so PHP 8.5
// (Ubuntu, KG9NI) failed this row. Prefer the version running this page.
$fpmVer = null;
$myVer  = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (file_exists("/run/php/php$myVer-fpm.sock")) {
    $fpmVer = $myVer;
} else {
    $vers = [];
    foreach (glob('/run/php/php*.*-fpm.sock') ?: [] as $sk) {
        if (preg_match('~php([0-9]+\.[0-9]+)-fpm\.sock$~', $sk, $m)) $vers[] = $m[1];
    }
    usort($vers, 'version_compare');
    if ($vers) $fpmVer = end($vers);
}
chk('PHP Environment','PHP-FPM socket exists', $fpmVer!==null,
    $fpmVer ? "php$fpmVer-fpm socket found (/run/php/php$fpmVer-fpm.sock)" : 'No PHP-FPM socket found',
    "sudo systemctl start php$myVer-fpm && sudo systemctl enable php$myVer-fpm");

foreach (['json'=>true,'curl'=>true,'sockets'=>true,'mbstring'=>true,
          'openssl'=>true,'pdo'=>true,'pdo_mysql'=>true,'xml'=>false] as $ext=>$req) {
    if (extension_loaded($ext)) {
        chkPass('PHP Environment',"Extension: $ext", 'Loaded');
    } elseif ($req) {
        chkFail('PHP Environment',"Extension: $ext", 'Missing — required',
            "sudo apt install php8.3-$ext");
    } else {
        chkWarn('PHP Environment',"Extension: $ext", 'Missing — recommended',
            "sudo apt install php8.3-$ext");
    }
}

// PHP settings
$maxExec = intval(ini_get('max_execution_time'));
chk('PHP Environment','max_execution_time ≥ 60', $maxExec >= 60,
    "${maxExec}s", 'Set max_execution_time = 90 in php.ini', $maxExec >= 30);

// ═══════════════════════════════════════════════════════════════════
// 2. WEB SERVER
// ═══════════════════════════════════════════════════════════════════
$nginx   = fileOk('/etc/nginx/nginx.conf');
$apache  = fileOk('/etc/apache2/apache2.conf');
$wsName  = $nginx ? 'Nginx' : ($apache ? 'Apache2' : 'Unknown');
chk('Web Server','Web server installed', $nginx||$apache,
    $wsName.' detected', 'sudo apt install nginx OR sudo apt install apache2');
// This page is behind the admin login, so the web server should have told
// PHP who logged in (nginx: BPQ_WEB_LOGIN, Apache: REMOTE_USER). 2026-10-04
require_once __DIR__ . '/includes/web-login.php';
$who = bpq_web_login_user();
chk('Web Server','Login reaches PHP', $who !== '',
    $who !== '' ? "Logged in as $who — the Email Client, NWS posting, Chat and APRS sending will accept requests"
                : "PHP wasn't told about a login for this page, so the Email Client, NWS posting, Chat, APRS sending and map saves will refuse",
    'nginx: add  fastcgi_param BPQ_WEB_LOGIN $remote_user;  inside every location with auth_basic that passes to PHP (install-check.php, the LOGIN section). Apache: protect this page with AuthType Basic.');

if ($nginx) {
    // Find the site config that serves this folder (2026-10-03: install.sh
    // names it "bpq-dashboard" with no .conf, and hand-built sites use their
    // own names, so match on "root <this folder>" rather than a file name).
    $cfgFile = '';
    $cands = array_merge(
        ['/etc/nginx/sites-enabled/bpq-dashboard', '/etc/nginx/sites-enabled/bpq-dashboard.conf',
         '/etc/nginx/sites-enabled/dashboard.conf'],
        glob('/etc/nginx/sites-enabled/*') ?: [], glob('/etc/nginx/conf.d/*.conf') ?: []);
    foreach (array_unique($cands) as $f) {
        if (!is_file($f) || !is_readable($f)) continue;
        $txt = @file_get_contents($f);
        if ($txt !== false && preg_match('~^\s*root\s+' . preg_quote(rtrim($BASE, '/'), '~') . '/?\s*;~m', $txt)) {
            $cfgFile = $f; break;
        }
    }
    $hasCfg = $cfgFile !== '';
    chk('Web Server','Nginx site config active', $hasCfg,
        $hasCfg ? basename($cfgFile).' serves '.$BASE : 'No file in sites-enabled has "root '.$BASE.';"',
        'Re-run install.sh, or copy bpq-dashboard.conf to /etc/nginx/sites-available/, link it into sites-enabled, then: sudo nginx -t && sudo systemctl reload nginx');

    if ($hasCfg) {
        $nc = file_get_contents($cfgFile);
        $ncAll = $nc . "\n" . (@file_get_contents('/etc/nginx/nginx.conf') ?: '');
        // 2026-10-30 (KG9NI): fastcgi_pass named /run/php/php-fpm.sock, which
        // didn't exist on that server, and every PHP page failed. Each socket
        // the site names must exist; the generic alias is only a link that
        // some systems don't have, so ask for the versioned path.
        preg_match_all('~^\s*fastcgi_pass\s+unix:([^;\s]+)\s*;~m', $nc, $fm);
        $fpSocks = array_values(array_unique($fm[1] ?? []));
        $fpGone  = array_values(array_filter($fpSocks, fn($p) => !file_exists($p)));
        $fpAlias = array_values(array_filter($fpSocks, fn($p) => !preg_match('~php[0-9]+\.[0-9]+-fpm\.sock$~', $p)));
        $fpFix   = "sudo sed -i 's|unix:/run/php/[^;]*|unix:/run/php/php$myVer-fpm.sock|' " . (realpath($cfgFile) ?: $cfgFile) . ' && sudo nginx -t && sudo systemctl reload nginx';
        if (!$fpSocks) {
            chkWarn('Web Server','PHP-FPM socket in site config', 'No "fastcgi_pass unix:…" lines found in ' . basename($cfgFile), 'Compare with bpq-dashboard.conf');
        } elseif ($fpGone) {
            chkFail('Web Server','PHP-FPM socket in site config', 'Site config names a socket that does not exist: ' . implode(', ', $fpGone) . ' — those locations answer 502', $fpFix);
        } elseif ($fpAlias) {
            chkWarn('Web Server','PHP-FPM socket in site config', 'Uses the generic ' . implode(', ', $fpAlias) . ' (an alias link that can be missing after an upgrade or reboot)', $fpFix);
        } else {
            chkPass('Web Server','PHP-FPM socket in site config', implode(', ', $fpSocks));
        }
        chk('Web Server','Rate limit zone configured', strpos($ncAll,'limit_req_zone')!==false,
            'Prevents API throttling', 'Add limit_req_zone to nginx.conf or site config');
        chk('Web Server','bpq-chat.php timeout (90s)', strpos($nc,'bpq-chat.php')!==false,
            '', 'Add location = /bpq-chat.php { fastcgi_read_timeout 90s; }');
        chk('Web Server','bpq-aprs.php timeout (60s)', strpos($nc,'bpq-aprs.php')!==false,
            '', 'Add location = /bpq-aprs.php { fastcgi_read_timeout 60s; }');
        // Admin login: every server block with this root needs /admin/ behind
        // auth_basic (or a redirect away). Count both to catch a second vhost.
        $roots  = preg_match_all('~^\s*root\s+' . preg_quote(rtrim($BASE, '/'), '~') . '/?\s*;~m', $nc);
        $guards = preg_match_all('#location\s+\^~\s+/admin/\s*\{\s*(auth_basic\s|return\s+30[12]\s)#', $nc);
        chk('Web Server','Admin folder behind the login', $guards >= max(1, $roots),
            $guards >= max(1, $roots)
                ? "location ^~ /admin/ guarded in $guards of $roots server block(s)"
                : "Only $guards of $roots server block(s) serving $BASE guard /admin/ — those pages may open with no password",
            'Add location ^~ /admin/ { auth_basic … } (see bpq-dashboard.conf), or a 301 to the protected host, to every server block with this root');
        // 2026-10-04: the Email Client APIs, NWS posting, Chat, APRS sending and
        // map saves refuse unless nginx passes BPQ_WEB_LOGIN from a location
        // with auth_basic (nginx's own REMOTE_USER can be forged by a visitor).
        $sigN    = preg_match_all('~fastcgi_param\s+BPQ_WEB_LOGIN\s+\$remote_user\s*;~', $nc);
        $missing = [];
        foreach (['bbs-messages', 'message-storage', 'nws-bbs-post', 'bpq-chat', 'station-storage', 'bpq-aprs', 'nts-forwarding-report'] as $ep) {
            if (strpos($nc, $ep) === false) $missing[] = "$ep.php";
        }
        $lockOk = $sigN > 0 && !$missing;
        chk('Web Server','Station-acting pages behind the login', $lockOk,
            $lockOk ? "Login signal set in $sigN location(s); Email Client, NWS, Chat, NTS report, APRS, map saves configured"
                    : ($sigN === 0 ? 'No "fastcgi_param BPQ_WEB_LOGIN $remote_user;" — those pages will refuse with "needs the dashboard admin login"'
                                   : 'No location for: ' . implode(', ', $missing)),
            'Compare your site file with nginx-bpq-dashboard.conf / bpq-dashboard.conf (LOGIN section), or re-run install.sh');
        // 2026-10-25: Telnet Client + VARA HF Terminal connect through /ws/telnet
        // and /ws/vara; the VARA bridge has no login of its own.
        $wsT = preg_match('#location\s+\^~\s+/ws/telnet\b#', $nc) > 0;
        $wsV = preg_match('#location\s+\^~\s+/ws/vara\s*\{[^}]*auth_basic\s#s', $nc) > 0;
        $wsVany = preg_match('#location\s+\^~\s+/ws/vara\b#', $nc) > 0;
        chk('Web Server','Telnet / VARA connections (/ws/telnet, /ws/vara)', $wsT && $wsV,
            ($wsT && $wsV) ? 'Both passed through; /ws/vara behind the login'
                : (!$wsT && !$wsVany ? 'Neither is in the site file — the Telnet and VARA pages can\'t connect'
                : (!$wsT ? 'No /ws/telnet — the Telnet page can\'t connect'
                : ($wsVany ? '/ws/vara has no auth_basic — anyone reaching it could transmit as your station'
                           : 'No /ws/vara — the VARA page can\'t connect'))),
            'Copy the /ws/telnet and /ws/vara blocks (and the bpq-telnet/bpq-vara page block) from nginx-bpq-dashboard.conf, or re-run install.sh', !$wsVany || $wsV);
        chk('Web Server','Admin pages LAN-restricted (optional)', strpos($nc,'allow 127.0.0.1')!==false,
            strpos($nc,'allow 127.0.0.1')!==false ? 'allow/deny rules found' : 'Not LAN-restricted — the admin login is the protection',
            'Optional: add allow/deny lines inside location ^~ /admin/ (see nginx-maintenance-block.conf)', true);
    }
} elseif ($apache) {
    $hasCfg = fileOk('/etc/apache2/sites-enabled/bpq-dashboard.conf');
    chk('Web Server','Apache site config active', $hasCfg,
        $hasCfg ? 'bpq-dashboard.conf found' : 'No site config found',
        'sudo a2ensite bpq-dashboard && sudo systemctl reload apache2');
    if ($hasCfg) {   // 2026-10-25: Telnet / VARA WebSocket proxy
        $ac  = (string)@file_get_contents('/etc/apache2/sites-enabled/bpq-dashboard.conf');
        $wsA = strpos($ac, '/ws/telnet') !== false && strpos($ac, '/ws/vara') !== false;
        $wst = fileOk('/etc/apache2/mods-enabled/proxy_wstunnel.load');
        chk('Web Server','Telnet / VARA connections (/ws/telnet, /ws/vara)', $wsA && $wst,
            $wsA ? ($wst ? 'ProxyPass for both; mod_proxy_wstunnel on' : 'ProxyPass found but mod_proxy_wstunnel is off')
                 : 'Not in the site file — the Telnet and VARA pages can\'t connect',
            'Re-run install.sh, or: sudo a2enmod proxy proxy_http proxy_wstunnel, add the /ws/ ProxyPass lines and <Location /ws/> login from install.sh, then sudo systemctl reload apache2', true);
    }
}

// SSL
$ssl = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on';
chk('Web Server','HTTPS/SSL active', $ssl,
    $ssl ? 'Running over HTTPS' : 'Running over HTTP (no SSL)',
    'sudo apt install certbot python3-certbot-nginx && sudo certbot --nginx -d yourdomain', !$ssl);

// ═══════════════════════════════════════════════════════════════════
// 3. DASHBOARD FILES
// ═══════════════════════════════════════════════════════════════════
$htmlFiles = [
    'bpq-rf-connections.html' => 'RF Connections',
    'bpq-traffic.html'        => 'Traffic Report',
    'bpq-system-logs.html'    => 'System Logs',
    'bpq-connect-log.html'    => 'Connect Log',
    'bpq-email-monitor.html'  => 'Message Monitor',
    'bbs-messages.html'       => 'Email Client',
    'nws-dashboard.html'      => 'NWS Weather',
    'bpq-chat.html'           => 'Chat Client',
    'bpq-aprs.html'           => 'APRS Map',
    'bpq-telnet.html'         => 'Telnet Client',
    'bpq-vara.html'           => 'VARA HF Terminal',
    'admin/bpq-maintenance.html' => 'Maintenance Page',
    'admin/firewall-status.html' => 'Firewall Status',
    'admin/system-audit.html'    => 'System Audit',
    'admin/log-viewer.html'      => 'Log Viewer',
];
foreach ($htmlFiles as $f => $label) {
    chk('Dashboard HTML',"$label ($f)", fileOk("$BASE/$f"),
        fileOk("$BASE/$f") ? 'Present' : 'Missing',
        "sudo cp $f $BASE/$f && sudo chown www-data:www-data $BASE/$f");
}

// 2026-10-05: Rig Status and RF Power Monitor need flrig; install.sh leaves
// them out when flrig is skipped (config.php radio.enabled = false).
// Only an explicit radio.enabled = false means "no flrig": installs whose
// config.php has no 'radio' section use vara-api.php's flrig defaults.
$flrigOn = ($cfg['radio']['enabled'] ?? true) !== false;
foreach (['rig-status.html' => 'Rig Status', 'rf-power-monitor.html' => 'RF Power Monitor'] as $f => $label) {
    if ($flrigOn) {
        chk('Dashboard HTML',"$label ($f)", fileOk("$BASE/$f"),
            fileOk("$BASE/$f") ? 'Present' : 'Missing',
            "sudo cp $f $BASE/$f && sudo chown www-data:www-data $BASE/$f");
    } else {
        chkPass('Dashboard HTML',"$label ($f)", 'Not installed — no flrig (config.php radio.enabled = false)');
    }
}

$phpFiles = [
    'config.php'                 => 'Main config (generated by installer)',
    'tprfn-db.php'               => 'Database layer',
    'message-db.php'             => 'Email Client storage',
    'station-info.php'           => 'Public station info',
    'network-api.php'            => 'Network data API',
    'bpq-chat.php'               => 'Chat broker',
    'bpq-aprs.php'               => 'APRS cache reader',
    'hub-report.php'             => 'Hub health report',
    'admin/partners-api.php'     => 'Partners editor API',
    'admin/firewall-api.php'     => 'Firewall API',
    'admin/system-audit-api.php' => 'System audit API',
    'admin/log-viewer-api.php'   => 'Log viewer API',
    'includes/admin-firewall.php' => 'Firewall rule reader (Firewall + Audit)',
];
foreach ($phpFiles as $f => $label) {
    chk('PHP Files',"$label ($f)", fileOk("$BASE/$f"),
        fileOk("$BASE/$f") ? 'Present' : 'Missing',
        "sudo cp $f $BASE/$f && sudo chown www-data:www-data $BASE/$f");
}

$scripts = [
    'scripts/bpq-chat-daemon.py'        => 'Chat daemon',
    'scripts/bpq-aprs-daemon.py'        => 'APRS daemon',
    'scripts/prop-scheduler.py'         => 'Propagation scheduler',
    'scripts/storm-monitor.py'          => 'Storm monitor',
    'scripts/connect-watchdog.py'       => 'Connection watchdog',
    'scripts/wp_manager.py'             => 'WP.cfg manager',
    'scripts/wp_scanner.py'             => 'WP.cfg scanner',
    'scripts/vara-callsign-validator.py'=> 'VARA callsign validator',
];
foreach ($scripts as $f => $label) {
    if (fileOk("$BASE/$f")) {
        $exec = is_executable("$BASE/$f");
        chkPass('Scripts',"$label — present", fileOk("$BASE/$f") ? 'Present' : '');
        chk('Scripts',"$label — executable", $exec,
            $exec ? 'OK' : 'Not executable', "sudo chmod +x $BASE/$f");
    } else {
        chkFail('Scripts',"$label ($f) — missing", 'Script not found',
            "sudo cp $f $BASE/$f && sudo chmod +x $BASE/$f");
    }
}

// ═══════════════════════════════════════════════════════════════════
// 4. DIRECTORIES & PERMISSIONS
// ═══════════════════════════════════════════════════════════════════
$dirs = [
    'cache'               => 'Base cache',
    'cache/aprs'          => 'APRS cache',
    'cache/chat-sessions' => 'Chat sessions cache',
    'cache/network'       => 'Network cache',
    'data'                => 'Data files',
    'scripts'             => 'Python scripts',
    'logs'                => 'Log files',
    'img'                 => 'Images/sprites',
];
foreach ($dirs as $dir => $label) {
    $fp = "$BASE/$dir";
    if (!is_dir($fp)) {
        chkFail('Directories',"$label ($dir)", 'Directory missing',
            "sudo mkdir -p $fp && sudo chown -R www-data:www-data $fp");
    } elseif ($dir === 'logs') {
        // logs/ is normally a link to LinBPQ's own log folder: the dashboard
        // only READS it (2026-09-26: was wrongly required to be writable).
        $n = count(glob("$fp/log_*.txt") ?: []);
        if (is_readable($fp))
            chkPass('Directories',"$label ($dir)", (is_link($fp) ? 'Linked to '.readlink($fp).', ' : '') . "readable, $n BPQ log file(s)");
        else
            chkFail('Directories',"$label ($dir)", 'Not readable by the web server',
                "sudo chmod o+rx " . (is_link($fp) ? readlink($fp) : $fp));
    } elseif (!is_writable($fp)) {
        chkFail('Directories',"$label ($dir)", 'Not writable by web server',
            "sudo chown -R www-data:www-data $fp && sudo chmod 775 $fp");
    } else {
        chkPass('Directories',"$label ($dir)", 'Present and writable');
    }
}

// APRS sprites
chk('Static Assets','APRS primary symbols (img/aprs-symbols-pri.png)',
    fileOk("$BASE/img/aprs-symbols-pri.png"),
    fileOk("$BASE/img/aprs-symbols-pri.png") ? filesize("$BASE/img/aprs-symbols-pri.png").' bytes' : 'Missing',
    "sudo wget -O $BASE/img/aprs-symbols-pri.png https://raw.githubusercontent.com/hessu/aprs-symbols/master/png/aprs-symbols-24-0.png");
chk('Static Assets','APRS alternate symbols (img/aprs-symbols-alt.png)',
    fileOk("$BASE/img/aprs-symbols-alt.png"),
    fileOk("$BASE/img/aprs-symbols-alt.png") ? filesize("$BASE/img/aprs-symbols-alt.png").' bytes' : 'Missing',
    "sudo wget -O $BASE/img/aprs-symbols-alt.png https://raw.githubusercontent.com/hessu/aprs-symbols/master/png/aprs-symbols-24-1.png");

// Message forms (downloaded by scripts/fetch-forms.sh; optional)
chk('Static Assets','Message forms (pktnet-forms/index.html)',
    fileOk("$BASE/pktnet-forms/index.html"),
    fileOk("$BASE/pktnet-forms/index.html") ? 'Present' : "Missing — the Email Client's PKTNET Forms button will 404",
    "sudo bash $BASE/scripts/fetch-forms.sh", true);

// ═══════════════════════════════════════════════════════════════════
// 5. CONFIG.PHP VALIDATION
// ═══════════════════════════════════════════════════════════════════
chk('Configuration','config.php present and parseable', !empty($cfg),
    !empty($cfg) ? 'Loaded OK' : 'Missing or parse error',
    'Copy config.php to web root and check for PHP syntax errors: php -l config.php');

if (!empty($cfg)) {
    chk('Configuration','Station callsign set', !empty($cfg['station']['callsign']??''),
        $cfg['station']['callsign']??'NOT SET', 'Set station.callsign in config.php');
    chk('Configuration','Station lat/lon set',
        !empty($cfg['station']['lat']??0) && ($cfg['station']['lat']??0)!=0,
        ($cfg['station']['lat']??0).', '.($cfg['station']['lon']??0),
        'Set station.lat and station.lon in config.php (decimal degrees)');
    chk('Configuration','BBS host configured', !empty($cfg['bbs']['host']??''),
        $cfg['bbs']['host']??'NOT SET', 'Set bbs.host in config.php');
    chk('Configuration','BBS port configured', !empty($cfg['bbs']['port']??''),
        isset($cfg['bbs']['port']) ? 'Port '.$cfg['bbs']['port'] : 'NOT SET',
        'Set bbs.port to 8010 in config.php');
    chk('Configuration','BBS user configured', !empty($cfg['bbs']['user']??''),
        $cfg['bbs']['user']??'NOT SET', 'Set bbs.user in config.php');
    $bpass = $cfg['bbs']['pass'] ?? '';
    $weakPass = in_array($bpass, ['changeme','password','bpq32','admin','test','']);
    chk('Configuration','BBS password set and not default', !empty($bpass) && !$weakPass,
        $weakPass ? '⚠ Weak/default password!' : 'Set', 'Change bbs.pass in config.php', $weakPass);
    // 2026-09-26: APRS settings live in the 'aprs' section now.
    $aprsCall = $cfg['aprs']['callsign'] ?? '';
    $aprsPass = (string)($cfg['aprs']['passcode'] ?? '');
    chk('Configuration','APRS login callsign', $aprsCall !== '' && stripos($aprsCall, 'YOURCALL') === false,
        $aprsCall !== '' ? $aprsCall : 'NOT SET', "Set aprs.callsign in config.php (e.g. YOURCALL-1)", true);
    if ($aprsPass !== '' && $aprsPass !== '-1')
        chkPass('Configuration','APRS-IS passcode', 'Set - can send to APRS-IS');
    else
        chkWarn('Configuration','APRS-IS passcode', 'Receive-only (passcode -1) - fine unless you want to send',
            'Set aprs.passcode in config.php - generate at https://apps.magicbug.co.uk/passcode/');
    // 2026-10-20: CORS was "*" by default; now same-origin unless configured.
    if (($cfg['cors']['allow_all'] ?? false) === true)
        chkWarn('Configuration','Other web sites (CORS)', "cors.allow_all is true - any web site can call this dashboard's endpoints from its visitors' browsers",
            "Set 'cors' => ['allow_all' => false, 'allowed_origins' => []] in config.php (list a site in allowed_origins only if it really needs to call the dashboard)");
    else
        chkPass('Configuration','Other web sites (CORS)', 'Same-origin only' . (!empty($cfg['cors']['allowed_origins']) ? ' + ' . implode(', ', (array)$cfg['cors']['allowed_origins']) : ''));
    // 2026-10-20: CARTO stamps "API KEY REQUIRED" on map tiles without a key.
    $cartoKey = trim((string)($cfg['map']['carto_key'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{8,128}$/', $cartoKey))
        chkPass('Configuration','CARTO map key (APRS map)', 'Set');
    else
        chkWarn('Configuration','CARTO map key (APRS map)',
            $cartoKey === '' ? 'Not set - APRS map tiles show "API KEY REQUIRED"' : 'Invalid characters - ignored',
            "Get a free key at https://www.carto.com/basemaps/apikey/ and set 'map' => ['carto_key' => '...'] in config.php");
    chk('Configuration','DB host configured', !empty($cfg['db']['host']??''),
        $cfg['db']['host']??'NOT SET', 'Set db.host in config.php');
    chk('Configuration','DB name configured', !empty($cfg['db']['name']??''),
        $cfg['db']['name']??'NOT SET', 'Set db.name in config.php');
}

// Data files
chk('Configuration','data/partners.json present', fileOk("$BASE/data/partners.json"),
    fileOk("$BASE/data/partners.json") ? 'Present' : 'Missing',
    "echo '{\"partners\":[]}' | sudo tee $BASE/data/partners.json && sudo chown www-data:www-data $BASE/data/partners.json");

// ═══════════════════════════════════════════════════════════════════
// 6. DATABASE
// ═══════════════════════════════════════════════════════════════════
if (!empty($cfg['db'])) {
    $db = $cfg['db'];
    try {
        $pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                       $db['user'], $db['pass'],
                       [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT=>5]);
        chkPass('Database','Database connection', "Connected to {$db['name']}@{$db['host']}");

        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        // Each group maps to one schema file — bbs-messages.html and
        // bpq-rf-connections.html both hard-depend on their tables
        // existing (no JSON-file fallback), so these are checked
        // separately from the propagation tables rather than lumped
        // into one generic list that used to miss them entirely.
        $tableGroups = [
            'Email Client (message storage)' => [
                'schema' => 'sql/2026-07-messages-schema.sql',
                'tables' => ['bpq_msg_folders', 'bpq_messages', 'bpq_addresses', 'bpq_msg_settings'],
            ],
            'RF Connections' => [
                'schema' => 'data/rf-connections-schema.sql',
                'tables' => ['rf_connections', 'rf_parser_state', 'rf_messages'],
            ],
            // 2026-09-26: groups now match the schema files exactly (the old
            // list expected 'hubs' / 'daily_summaries', which no schema
            // creates, so a correct fresh install showed red "Missing").
            'Network sessions' => [
                'schema' => 'data/sessions-stations-schema.sql',
                'tables' => ['sessions', 'stations'],
            ],
            'Traffic Report' => [
                'schema' => 'data/traffic-schema.sql',
                'tables' => ['traffic_stats', 'traffic_parser_state'],
            ],
            'System Logs' => [
                'schema' => 'data/system-logs-schema.sql',
                'tables' => ['sys_events', 'sys_station_daily', 'sys_logs_parser_state'],
            ],
            'Propagation history' => [
                'schema' => 'data/prop-decisions-schema.sql',
                'tables' => ['prop_decisions'],
            ],
        ];
        foreach ($tableGroups as $groupLabel => $group) {
            foreach ($group['tables'] as $t) {
                if (in_array($t, $tables)) {
                    $cnt = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                    chkPass('Database', "$groupLabel — $t", "$cnt rows");
                } else {
                    chkFail('Database', "$groupLabel — $t", 'Missing',
                        "mysql -u{$db['user']} -p {$db['name']} < {$group['schema']}");
                }
            }
        }
    } catch (Exception $e) {
        chkFail('Database','Database connection', $e->getMessage(),
            'Check db credentials in config.php — ensure MariaDB is running: sudo systemctl start mariadb');
    }
} else {
    chkWarn('Database','Database config', 'config.php not loaded — cannot check database');
}

// ═══════════════════════════════════════════════════════════════════
// 7. DAEMONS
// ═══════════════════════════════════════════════════════════════════
$daemons = [
    'bpq-chat' => [
        'label'   => 'BPQ Chat daemon',
        'hb_file' => "$BASE/cache/chat-sessions/chat-daemon.json",
        'max_age' => 30,
        'service_file' => '/etc/systemd/system/bpq-chat.service',
        'script'  => "$BASE/scripts/bpq-chat-daemon.py",
    ],
    'bpq-aprs' => [
        'label'   => 'BPQ APRS daemon',
        'hb_file' => "$BASE/cache/aprs/aprs-daemon.json",
        'max_age' => 60,
        'service_file' => '/etc/systemd/system/bpq-aprs.service',
        'script'  => "$BASE/scripts/bpq-aprs-daemon.py",
    ],
];

foreach ($daemons as $svc => $d) {
    // Service file
    chk('Daemons',"{$d['label']} — service file", fileOk($d['service_file']),
        fileOk($d['service_file']) ? 'Present' : 'Missing — run installer first',
        "sudo systemctl daemon-reload && sudo systemctl enable $svc");
    // Script
    chk('Daemons',"{$d['label']} — script", fileOk($d['script']),
        fileOk($d['script']) ? 'Present' : 'Script missing',
        "Copy {$d['script']} from the dashboard archive");
    // Heartbeat
    $age = hbAge($d['hb_file']);
    $hbData = hbData($d['hb_file']);
    if ($age === null) {
        chkFail('Daemons',"{$d['label']} — heartbeat",
            'No heartbeat file — daemon not running',
            "sudo systemctl start $svc");
    } elseif ($age > $d['max_age']) {
        chkFail('Daemons',"{$d['label']} — heartbeat",
            "Stale: last beat {$age}s ago (max {$d['max_age']}s)",
            "sudo systemctl restart $svc");
    } else {
        $extra = '';
        if ($svc==='bpq-aprs' && !empty($hbData['stations'])) {
            $extra = " — {$hbData['stations']} stations, {$hbData['packets']} packets";
        }
        chkPass('Daemons',"{$d['label']} — heartbeat", "{$age}s ago{$extra}");
    }
}

// Optional daemons
$optDaemons = [
    'vara-validator' => 'VARA callsign validator',
];
foreach ($optDaemons as $svc => $label) {
    $sf = "/etc/systemd/system/$svc.service";
    if (fileOk($sf)) {
        chkWarn('Daemons',"$label — installed but status unknown",
            'Check: sudo systemctl status '.$svc,
            'sudo systemctl enable --now '.$svc);
    } else {
        chkWarn('Daemons',"$label — not installed (optional)",
            'Only needed if using VARA HF with callsign validation',
            'Run installer and select VARA validator option');
    }
}

// 2026-10-27: VARA HF log for RF Connections (Installation Manual §10.11):
// VARA HF sends syslog (UDP 514) -> rsyslog -> /var/log/syslog ->
// vara-logger -> logs/<vara_file>. Warnings only: VARA HF is optional.
$udp514 = preg_match('/:514\s/', (string)shell_exec('ss -uln 2>/dev/null')) === 1;
chk('Daemons', 'VARA HF log — syslog receiver (UDP 514)', $udp514,
    $udp514 ? 'This server listens for syslog on UDP 514'
            : 'Nothing listens on UDP 514 — VARA HF\'s log can\'t arrive',
    'Re-run install.sh and answer Yes to the VARA HF log question, or set up rsyslog by hand (Installation Manual §10.11)', true);
$vfName = basename((string)($cfg['logs']['vara_file'] ?? ''));
$vfDir  = rtrim((string)($cfg['paths']['logs'] ?? "$BASE/logs"), '/');
if ($vfName !== '') {
    $vf  = "$vfDir/$vfName";
    $vSz = fileOk($vf) ? (int)@filesize($vf) : -1;
    $vAg = fileOk($vf) ? time() - (int)@filemtime($vf) : null;
    chk('Daemons', "VARA HF log — $vfName", $vSz > 0 && $vAg !== null && $vAg < 7 * 86400,
        $vSz < 0  ? "Not found in $vfDir — vara-logger creates it when it starts"
      : ($vSz === 0 ? 'Empty — no VARA HF lines received yet'
      : ($vAg >= 7 * 86400 ? 'Last line ' . floor($vAg / 86400) . ' days ago' : 'Last line ' . ($vAg < 3600 ? floor($vAg / 60) . ' min' : floor($vAg / 3600) . ' h') . ' ago')),
        "In VARA HF: Settings > VARA Setup > Syslog on, Host = this server, port 514, Station Name H-<call-SSID>; then: sudo systemctl enable --now vara-logger; grep VARAHF /var/log/syslog | tail -3", true);
}

// ═══════════════════════════════════════════════════════════════════
// 8. LINBPQ CONNECTIVITY
// ═══════════════════════════════════════════════════════════════════
$bbsHost = $cfg['bbs']['host'] ?? 'localhost';
$bbsPort = intval($cfg['bbs']['port'] ?? 8010);

chk('LinBPQ','Telnet port reachable ('.$bbsHost.':'.$bbsPort.')',
    tcpOpen($bbsHost,$bbsPort,3),
    tcpOpen($bbsHost,$bbsPort,3) ? 'Connected' : 'Cannot connect',
    'Ensure LinBPQ is running and TCPPORT='.($bbsPort?$bbsPort:8010).' is set in bpq32.cfg');

// Check bpq32.cfg if accessible
$cfgPaths = array_values(array_unique(array_filter([
    !empty($cfg['paths']['linbpq']) ? rtrim($cfg['paths']['linbpq'], '/') . '/bpq32.cfg' : null,
    '/home/linbpq/bpq32.cfg', '/home/pi/linbpq/bpq32.cfg', '/etc/linbpq/bpq32.cfg'])));
$bpqCfg = null;
foreach ($cfgPaths as $p) { if (fileOk($p)) { $bpqCfg = $p; break; } }

if ($bpqCfg) {
    chkPass('LinBPQ','bpq32.cfg found', $bpqCfg);
    $bc = file_get_contents($bpqCfg);
    chk('LinBPQ','NODECALL set',    preg_match('/^NODECALL\s*=/mi',$bc)>0, '', 'Set NODECALL=YOURCALL-N');
    // LinBPQ turns on its mail and chat servers either with LINMAIL / LINCHAT
    // lines in bpq32.cfg or with "mail" / "chat" on its command line
    // (e.g. ExecStart=.../linbpq mail chat). Accept either (2026-10-03).
    // Only the linbpq process itself (by program name), not any command line
    // that merely mentions it.
    $bpqArgs = strtolower((string)@shell_exec("ps -C linbpq -o args= 2>/dev/null"));
    foreach (['LINMAIL' => 'mail', 'LINCHAT' => 'chat'] as $kw => $arg) {
        $inCfg = preg_match('/^\s*' . $kw . '\b/mi', $bc) > 0;
        $inCmd = preg_match('/\b' . $arg . '\b/', $bpqArgs) > 0;
        chk('LinBPQ', "$kw enabled", $inCfg || $inCmd,
            $inCfg ? "$kw line in bpq32.cfg" : ($inCmd ? "\"$arg\" on the running linbpq command line" : 'Not in bpq32.cfg and not on the linbpq command line'),
            "Add $kw to bpq32.cfg, or start linbpq with \"$arg\" (then restart LinBPQ)");
    }
    chk('LinBPQ','BBS APPLICATION', preg_match('/^APPLICATION.*BBS/mi',$bc)>0,  '', 'Add APPLICATION line for BBS');
    chk('LinBPQ','CHAT APPLICATION',preg_match('/^APPLICATION.*CHAT/mi',$bc)>0, '', 'Add APPLICATION line for CHAT');
    chk('LinBPQ','TCPPORT in config', preg_match('/^\s*TCPPORT\s*=/mi',$bc)>0,
        'TCPPORT setting in bpq32.cfg telnet port section',
        'Set TCPPORT=8010 in the PORT/DRIVER=TELNET section', true);
    // 2026-10-25: the VARA HF Terminal (bpq-vara) attaches this port
    $vWant = (int)($cfg['vara']['bpq_port'] ?? 0);
    $vPort = 0; $vPos = 0;
    $bcNc  = preg_replace('#/\*.*?\*/#s', '', $bc);
    if (preg_match_all('/^\s*PORT\b(.*?)^\s*ENDPORT\b/smi', $bcNc, $pm)) {
        foreach ($pm[1] as $blk) {
            $vPos++;
            $blk = implode("\n", array_map(fn($l) => explode(';', $l, 2)[0], explode("\n", $blk)));
            $num = preg_match('/^\s*PORTNUM\s*=\s*(\d+)/mi', $blk, $nm) ? (int)$nm[1] : $vPos;
            if (!preg_match('/^\s*(DRIVER\s*=\s*VARA|DLLNAME\s*=\s*VARA)/mi', $blk)) continue;
            if (preg_match('/VARA\s*-?\s*FM/i', $blk)) continue;
            $vPort = $num; break;
        }
    }
    chk('LinBPQ', 'VARA HF port (VARA HF Terminal)', $vWant > 0 || $vPort > 0,
        $vWant > 0 ? "config.php vara.bpq_port = $vWant" . ($vPort && $vPort !== $vWant ? " (bpq32.cfg's first VARA HF port is $vPort)" : '')
                   : ($vPort ? "Port $vPort (first DRIVER=VARA port that isn't VARA FM)" : 'No DRIVER=VARA port found'),
        "Only needed for the VARA HF Terminal. Add a VARA port to bpq32.cfg, or set 'vara' => ['bpq_port' => N] in config.php", true);
    // 2026-10-05: the Rig page lists the scan schedule of the FLRIG RADIO block
    if ($flrigOn) {
        require_once "$BASE/includes/bpq-radio.php";
        $want  = (int)($cfg['radio']['bpq_radio'] ?? 0);
        $hp    = ($cfg['radio']['flrig_host'] ?? '127.0.0.1') . ':' . ($cfg['radio']['flrig_port'] ?? 12345);
        $rb    = bpqPickFlrigBlock(bpqRadioBlocks($bc), $want ?: null, $hp);
        $nChan = $rb ? array_sum(array_map(fn($b) => count($b['channels']), bpqScanBands($rb))) : 0;
        chk('LinBPQ', 'FLRIG RADIO block (Rig page scan list)', $rb && $nChan > 0,
            $rb ? "RADIO {$rb['radio']} (FLRIG {$rb['flrig']}), $nChan scan channel(s)"
                : ($want ? "No RADIO $want block with an FLRIG line" : 'No RADIO block with an FLRIG line'),
            $want ? 'Check config.php radio.bpq_radio, or set it to 0 for automatic'
                  : 'Add a RADIO n block with "FLRIG host:port" and its scan channels to bpq32.cfg', true);
    }
} else {
    chkWarn('LinBPQ','bpq32.cfg not readable',
        'Not found or not readable by web server — checks skipped',
        'Manual check required: ensure LINMAIL, LINCHAT, and correct APPLICATION lines are present');
}

// ═══════════════════════════════════════════════════════════════════
// 9. NETWORK CONNECTIVITY
// ═══════════════════════════════════════════════════════════════════
$netTests = [
    ['rotate.aprs2.net',  14580, 'APRS-IS (rotate.aprs2.net:14580)', false,
     'sudo iptables -I OUTPUT -p tcp --dport 14580 -j ACCEPT'],
    ['api.weather.gov',   443,   'NWS Weather API (api.weather.gov:443)', false,
     'Check outbound HTTPS/443'],
    ['server.winlink.org',8085,  'WinLink CMS (server.winlink.org:8085)', true,
     'Optional — needed for Winlink reporting'],
];
foreach ($netTests as [$host,$port,$label,$optional,$fix]) {
    $ok = tcpOpen($host,$port,5);
    if ($ok) chkPass('Network',$label,'Reachable');
    elseif ($optional) chkWarn('Network',$label,'Not reachable (optional)',$fix);
    else chkFail('Network',$label,'Cannot connect — check firewall',$fix);
}

// ═══════════════════════════════════════════════════════════════════
// 10. SYSTEMD SERVICE FILES
// ═══════════════════════════════════════════════════════════════════
$service_files = [
    '/etc/systemd/system/bpq-chat.service'       => 'BPQ Chat daemon',
    '/etc/systemd/system/bpq-aprs.service'       => 'BPQ APRS daemon',
    '/etc/systemd/system/vara-validator.service'  => 'VARA callsign validator',
];
foreach ($service_files as $sf => $label) {
    if (!file_exists($sf)) {
        $optional = strpos($sf, 'vara-validator') !== false;
        ($optional ? 'chkWarn' : 'chkFail')('Services', "$label service file" . ($optional ? ' (optional)' : ''), 'Missing: '.$sf,
            'sudo cp '.basename($sf).' /etc/systemd/system/ && sudo systemctl daemon-reload');
    } else {
        $svc = file_get_contents($sf);
        chkPass('Services', "$label service file", $sf);
        // Check correct script path
        $scriptMap = [
            'bpq-chat.service'      => "$BASE/scripts/bpq-chat-daemon.py",
            'bpq-aprs.service'      => "$BASE/scripts/bpq-aprs-daemon.py",
        ];
        $bn = basename($sf);
        if (isset($scriptMap[$bn])) {
            $sp = $scriptMap[$bn];
            if (strpos($svc, $sp) === false)
                chkFail('Services', "$label ExecStart path", "Should point to $sp",
                    "Edit $sf: ExecStart=/usr/bin/python3 $sp");
            else
                chkPass('Services', "$label ExecStart path", $sp);
        }
        // www-data user
        if ($bn !== 'vara-validator.service') {
            $hasUser = strpos($svc,'www-data') !== false;
            if (!$hasUser)
                chkFail('Services', "$label User=www-data", 'Not set — daemon may lack cache write access',
                    "Add User=www-data Group=www-data to [Service] in $sf");
            else
                chkPass('Services', "$label User=www-data", 'Set correctly');
        }
        // Restart=always
        if (strpos($svc,'Restart=always') === false)
            chkWarn('Services', "$label Restart=always", 'Missing — daemon will not auto-restart on crash',
                "Add Restart=always to [Service] in $sf");
        else
            chkPass('Services', "$label Restart=always", 'Set');
    }
}

// ═══════════════════════════════════════════════════════════════════
// 10b. PYTHON MODULES (2026-09-26)
foreach (['pymysql' => 'log parsers / database (apt install python3-pymysql)',
          'reportlab' => 'RF report PDFs (apt install python3-reportlab)',
          'websockets' => 'telnet / chat daemons (apt install python3-websockets)'] as $mod => $why) {
    $out = @shell_exec('python3 -c ' . escapeshellarg("import $mod") . ' 2>&1 && echo __OK__');
    if ($out !== null && strpos($out, '__OK__') !== false)
        chkPass('Python modules', "python3 $mod", 'Installed');
    else
        chkFail('Python modules', "python3 $mod", "Missing - needed for $why",
            'sudo apt install -y python3-' . $mod);
}

// 11. CRON JOBS
// ═══════════════════════════════════════════════════════════════════
$rootCron = shell_exec('sudo crontab -l 2>/dev/null') ?: '';
$userCron = shell_exec('crontab -u '.get_current_user().' -l 2>/dev/null') ?: '';
$allCron  = $rootCron . $userCron . (shell_exec('cat /etc/cron.d/* 2>/dev/null') ?: '');

// connect-watchdog: must be in root only
$wdRoot = strpos($rootCron,'connect-watchdog') !== false;
$wdUser = strpos($userCron,'connect-watchdog') !== false;
if ($wdRoot && !$wdUser)
    chkPass('Cron Jobs','connect-watchdog (root cron, every 5 min)', 'Root only — correct');
elseif ($wdRoot && $wdUser)
    chkFail('Cron Jobs','connect-watchdog DUPLICATE RACE CONDITION',
        'In BOTH root and user crontab — causes state file conflicts, watchdog never fires!',
        'crontab -e   # remove connect-watchdog line from USER crontab, keep root only');
else
    // Optional feature (manual 10.1) — and the web server can't read
    // root's crontab, so "not found" may just mean "not visible".
    chkWarn('Cron Jobs','connect-watchdog not scheduled (optional)',
        'Only needed if you use the Connect Watchdog',
        '# Add to root crontab (sudo crontab -e):'."
".'*/5 * * * * /usr/bin/python3 '.$BASE.'/scripts/connect-watchdog.py >> /var/log/connect-watchdog.log 2>&1');

// Dashboard log parsers (installed by install.sh as /etc/cron.d/bpq-dashboard)
$parsersFound = [];
foreach (['parse-vara-log', 'parse-traffic-report', 'parse-system-logs'] as $p) {
    if (strpos($allCron, $p) !== false) $parsersFound[] = $p;
}
if (count($parsersFound) === 3)
    chkPass('Cron Jobs','Log parsers scheduled (RF Connections, Traffic, System Logs)', 'All three found');
else
    chkWarn('Cron Jobs','Log parsers not (all) scheduled',
        'Found: ' . ($parsersFound ? implode(', ', $parsersFound) : 'none') . ' - database pages will not update',
        'Re-run install.sh, or see USER-MANUAL.md section 4 (manual install, "Schedule the log parsers")');

// wp_manager auto-clean: 3am daily
$wpSched = strpos($allCron,'wp_manager') !== false && strpos($allCron,'auto-clean') !== false;
if ($wpSched) chkPass('Cron Jobs','wp_manager --auto-clean (3am daily)', 'Scheduled');
else chkWarn('Cron Jobs','wp_manager --auto-clean not scheduled',
    'WP.cfg will not be automatically cleaned',
    '# sudo crontab -e — add:'."
".'0 3 * * * cd '.(!empty($cfg['paths']['linbpq']) ? rtrim($cfg['paths']['linbpq'],'/') : '<your linbpq dir>').' && /usr/bin/python3 '.$BASE.'/scripts/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1');

// prop-scheduler
if (strpos($allCron,'prop-scheduler') !== false)
    chkPass('Cron Jobs','prop-scheduler scheduled', 'Present in crontab');
else
    chkWarn('Cron Jobs','prop-scheduler not scheduled', 'May be run manually — OK if intended', '');

// storm-monitor
if (strpos($allCron,'storm-monitor') !== false)
    chkPass('Cron Jobs','storm-monitor scheduled', 'Present in crontab');
else
    chkWarn('Cron Jobs','storm-monitor not scheduled', 'Optional — needed for Kp-based suspend', '');

// Malicious cron check (from security audit — /boot/nnnn and sysmd.e)
$malicious = preg_match('/\/boot\/[0-9]+|sysmd\.e/', $allCron);
if ($malicious)
    chkFail('Cron Jobs','MALICIOUS CRON ENTRY DETECTED',
        'Possible backdoor — review ALL crontabs immediately',
        'sudo crontab -l && crontab -l && cat /etc/cron.d/*');
else
    chkPass('Cron Jobs','No malicious cron entries', 'Clean');

// ═══════════════════════════════════════════════════════════════════
// 12. LOG FILES
// ═══════════════════════════════════════════════════════════════════
foreach ([
    '/var/log/connect-watchdog.log' => 'Connect watchdog',
    '/var/log/vara-validator.log'   => 'VARA validator',
    '/var/log/wp-auto-clean.log'    => 'WP auto-clean',
] as $lf => $label) {
    if (!file_exists($lf))
        chkWarn('Log Files',"$label log ($lf)", 'Not yet created — normal if script never ran',
            "sudo touch $lf && sudo chmod 644 $lf");
    else {
        $kb  = round(filesize($lf)/1024,1);
        $age = round((time()-filemtime($lf))/60);
        chkPass('Log Files',"$label log", "${kb} KB, last modified {$age}m ago");
    }
}

// ═══════════════════════════════════════════════════════════════════
// 13. SECURITY
// ═══════════════════════════════════════════════════════════════════
chkWarn('Security','Remove install-check.php after use',
    'This file exposes system configuration — delete when done',
    'sudo rm '.$BASE.'/install-check.php');

$cfgPerms = fileperms("$BASE/config.php");
chk('Security','config.php not world-readable', ($cfgPerms & 0x0004) === 0,
    'World-readable bit is '.((($cfgPerms & 0x0004)>0)?'SET (insecure!)':'clear (OK)'),
    "sudo chmod 640 $BASE/config.php", ($cfgPerms & 0x0004) > 0);

// ═══════════════════════════════════════════════════════════════════
// RENDER
// ═══════════════════════════════════════════════════════════════════
$total = $pass+$fail+$warn;
$score = $total>0 ? round($pass/$total*100) : 0;
$sc    = $score>=90?'#3fb950':($score>=70?'#d29922':'#f85149');
renderPage($results,$pass,$fail,$warn,$score,$sc);

// ── Login page ─────────────────────────────────────────────────────
function showLogin(string $why) {
    http_response_code(403);
    header('Cache-Control: no-store'); ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BPQ Dashboard — Install Check</title>
<style>
body{margin:0;background:#0d1117;color:#e6edf3;font-family:system-ui,sans-serif;
     display:flex;align-items:center;justify-content:center;min-height:100vh;}
.box{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:32px;max-width:560px;margin:16px;}
h2{color:#4a9eff;margin:0 0 8px;}p{color:#8b949e;font-size:14px;line-height:1.5;margin:0;}
</style></head><body>
<div class="box">
<h2>🔒 Install Check needs the admin login</h2>
<p><?=htmlspecialchars($why)?></p>
</div></body></html>
<?php }

// ── Main render ────────────────────────────────────────────────────
function renderPage($results,$pass,$fail,$warn,$score,$sc) {
$total = $pass+$fail+$warn; ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BPQ Dashboard v1.5.15 — Install Check</title>
<style>
:root{--bg:#0d1117;--sur:#161b22;--bdr:#30363d;--txt:#e6edf3;--mut:#8b949e;
      --acc:#4a9eff;--grn:#3fb950;--red:#f85149;--org:#d29922;--pur:#bc8cff;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--txt);font-family:system-ui,sans-serif;font-size:13px;
     padding:20px;max-width:900px;margin:0 auto;}
h1{font-size:20px;color:var(--acc);margin-bottom:4px;}
.sub{color:var(--mut);font-size:11px;margin-bottom:20px;font-family:monospace;}
.card{background:var(--sur);border:1px solid var(--bdr);border-radius:8px;
      padding:18px 22px;margin-bottom:20px;display:flex;align-items:center;gap:20px;}
.score{font-size:54px;font-weight:700;line-height:1;font-family:monospace;}
.score-info{flex:1;}
.score-label{font-size:12px;color:var(--mut);margin-bottom:8px;}
.badges{display:flex;gap:8px;flex-wrap:wrap;}
.badge{padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;}
.bp{background:rgba(63,185,80,.15);color:var(--grn);}
.bf{background:rgba(248,81,73,.15);color:var(--red);}
.bw{background:rgba(210,153,34,.15);color:var(--org);}
.prog{height:5px;background:var(--bdr);border-radius:3px;margin-top:10px;overflow:hidden;}
.pf{height:100%;border-radius:3px;transition:width .6s;}
.cat{margin-bottom:14px;}
.ch{background:var(--sur);border:1px solid var(--bdr);border-radius:6px 6px 0 0;
    padding:8px 16px;font-weight:700;font-size:12px;text-transform:uppercase;
    letter-spacing:.5px;color:var(--acc);display:flex;align-items:center;justify-content:space-between;}
.cb{border:1px solid var(--bdr);border-top:none;border-radius:0 0 6px 6px;overflow:hidden;}
.row{display:grid;grid-template-columns:24px 1fr auto;gap:8px;align-items:start;
     padding:8px 16px;border-bottom:1px solid rgba(255,255,255,.04);}
.row:last-child{border-bottom:none;}
.row.pass{border-left:3px solid var(--grn);}
.row.fail{border-left:3px solid var(--red);background:rgba(248,81,73,.04);}
.row.warn{border-left:3px solid var(--org);background:rgba(210,153,34,.04);}
.ico{font-size:14px;padding-top:1px;}
.nm{font-weight:600;font-size:12px;margin-bottom:2px;}
.det{font-size:11px;color:var(--mut);}
.fix{font-size:11px;color:var(--pur);margin-top:3px;font-family:monospace;
     word-break:break-all;cursor:pointer;user-select:all;
     background:rgba(188,140,255,.08);padding:2px 6px;border-radius:3px;display:inline-block;}
.fix::before{content:"▶ ";color:var(--acc);}
.st{font-size:10px;font-weight:700;white-space:nowrap;padding-top:2px;}
.pass .st{color:var(--grn);}.fail .st{color:var(--red);}.warn .st{color:var(--org);}
.footer{margin-top:24px;padding:14px 18px;background:rgba(248,81,73,.08);
        border:1px solid rgba(248,81,73,.3);border-radius:8px;
        color:var(--org);font-size:12px;text-align:center;line-height:1.8;}
.footer strong{color:var(--red);}
</style></head><body>
<h1>🔧 BPQ Dashboard v1.5.15 — Post-Installation Check</h1>
<p class="sub"><?=date('Y-m-d H:i:s T')?> · PHP <?=phpversion()?> · <?=htmlspecialchars(php_uname('n'))?></p>

<div class="card">
    <div class="score" style="color:<?=$sc?>"><?=$score?>%</div>
    <div class="score-info">
        <div class="score-label">Installation Health · <?=$total?> checks</div>
        <div class="badges">
            <span class="badge bp">✓ <?=$pass?> passed</span>
            <span class="badge bf">✗ <?=$fail?> failed</span>
            <span class="badge bw">⚠ <?=$warn?> warnings</span>
        </div>
        <div class="prog"><div class="pf" style="width:<?=$score?>%;background:<?=$sc?>"></div></div>
    </div>
</div>

<?php
$cats = [];
foreach ($results as $r) $cats[$r['cat']][] = $r;
foreach ($cats as $cat => $items):
    $nf = count(array_filter($items,fn($i)=>$i['status']==='fail'));
    $nw = count(array_filter($items,fn($i)=>$i['status']==='warn'));
    $badge = $nf ? "❌ $nf issue".($nf>1?'s':'') : ($nw ? "⚠ $nw warning".($nw>1?'s':'') : '✅ All OK');
    $bc    = $nf?'#f85149':($nw?'#d29922':'#3fb950');
?>
<div class="cat">
<div class="ch"><span><?=htmlspecialchars($cat)?></span>
    <span style="color:<?=$bc?>;font-size:11px;font-weight:400"><?=$badge?></span></div>
<div class="cb">
<?php foreach ($items as $r):
    $ico = $r['status']==='pass'?'✅':($r['status']==='warn'?'⚠️':'❌');
?>
<div class="row <?=$r['status']?>">
<span class="ico"><?=$ico?></span>
<div>
    <div class="nm"><?=htmlspecialchars($r['name'])?></div>
    <?php if ($r['detail']): ?><div class="det"><?=htmlspecialchars($r['detail'])?></div><?php endif ?>
    <?php if ($r['status']!=='pass' && $r['fix']): ?><span class="fix" title="Click to select/copy"><?=htmlspecialchars($r['fix'])?></span><?php endif ?>
</div>
<span class="st"><?=strtoupper($r['status'])?></span>
</div>
<?php endforeach ?>
</div></div>
<?php endforeach ?>

<div class="footer">
    <strong>⚠ Security reminder:</strong> Remove this file when done troubleshooting.<br>
    <code>sudo rm <?=htmlspecialchars($GLOBALS['BASE'])?>/install-check.php</code><br>
    BPQ Dashboard v1.5.15 · Access: <?=htmlspecialchars("https://".($_SERVER['HTTP_HOST'] ?? 'your-server')."/install-check.php")?> (admin login)
</div>
</body></html>
<?php }
