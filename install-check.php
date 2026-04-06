<?php
/**
 * BPQ Dashboard v1.5.5 — Post-Installation Check
 * Checks every component installed by bpq-dashboard-install.sh
 * Access: https://your.domain/install-check.php?pass=bpqcheck
 * REMOVE THIS FILE after troubleshooting is complete.
 */

// ── Auth ───────────────────────────────────────────────────────────
define('CHECK_PASS', 'bpqcheck');
if (($_GET['pass'] ?? '') !== CHECK_PASS) { showLogin(); exit; }

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

// Detect active FPM
$fpmVer = null;
foreach (['8.4','8.3','8.2','8.1'] as $v) {
    if (fileOk("/run/php/php$v-fpm.sock")) { $fpmVer = $v; break; }
}
chk('PHP Environment','PHP-FPM socket exists', $fpmVer!==null,
    $fpmVer ? "php$fpmVer-fpm socket found" : 'No PHP-FPM socket found',
    'sudo systemctl start php8.3-fpm && sudo systemctl enable php8.3-fpm');

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

if ($nginx) {
    // Check for our config
    $hasCfg = fileOk('/etc/nginx/sites-enabled/bpq-dashboard.conf')
           || fileOk('/etc/nginx/sites-enabled/tprfn.conf');
    chk('Web Server','Nginx site config active', $hasCfg,
        $hasCfg ? 'bpq-dashboard.conf or tprfn.conf found' : 'No site config found',
        'sudo cp bpq-dashboard.conf /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx');

    if ($hasCfg) {
        $cfgFile = fileOk('/etc/nginx/sites-enabled/bpq-dashboard.conf')
                 ? '/etc/nginx/sites-enabled/bpq-dashboard.conf'
                 : '/etc/nginx/sites-enabled/tprfn.conf';
        $nc = file_get_contents($cfgFile);
        chk('Web Server','Rate limit zone configured', strpos($nc,'limit_req_zone')!==false,
            'Prevents API throttling', 'Add limit_req_zone to nginx.conf or site config');
        chk('Web Server','bpq-chat.php timeout (90s)', strpos($nc,'bpq-chat.php')!==false,
            '', 'Add location = /bpq-chat.php { fastcgi_read_timeout 90s; }');
        chk('Web Server','bpq-aprs.php timeout (60s)', strpos($nc,'bpq-aprs.php')!==false,
            '', 'Add location = /bpq-aprs.php { fastcgi_read_timeout 60s; }');
        chkWarn('Web Server','Admin pages LAN-restricted',
            strpos($nc,'bpq-maintenance')!==false || strpos($nc,'allow 127.0.0.1')!==false
                ? 'LAN restriction found' : 'No LAN restriction detected — admin pages may be public',
            'Add allow 10.0.0.0/8; deny all; to admin location blocks');
    }
} elseif ($apache) {
    $hasCfg = fileOk('/etc/apache2/sites-enabled/bpq-dashboard.conf');
    chk('Web Server','Apache site config active', $hasCfg,
        $hasCfg ? 'bpq-dashboard.conf found' : 'No site config found',
        'sudo a2ensite bpq-dashboard && sudo systemctl reload apache2');
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
    'bpq-email-monitor.html'  => 'Email Monitor',
    'bbs-messages.html'       => 'BBS Messages',
    'nws-dashboard.html'      => 'NWS Weather',
    'bpq-chat.html'           => 'Chat Client',
    'bpq-aprs.html'           => 'APRS Map',
    'bpq-maintenance.html'    => 'Maintenance Page',
    'hub-ops.html'            => 'Hub Operations',
    'firewall-status.html'    => 'Firewall Status',
    'system-audit.html'       => 'System Audit',
    'log-viewer.html'         => 'Log Viewer',
];
foreach ($htmlFiles as $f => $label) {
    chk('Dashboard HTML',"$label ($f)", fileOk("$BASE/$f"),
        fileOk("$BASE/$f") ? 'Present' : 'Missing',
        "sudo cp $f $BASE/$f && sudo chown www-data:www-data $BASE/$f");
}

$phpFiles = [
    'config.php'           => 'Main config (generated by installer)',
    'tprfn-config.php'     => 'Config compatibility shim',
    'network-api.php'      => 'Network data API',
    'tprfn-db.php'         => 'Database layer',
    'bpq-chat.php'         => 'Chat broker',
    'bpq-aprs.php'         => 'APRS cache reader',
    'partners-api.php'     => 'Partners editor API',
    'firewall-api.php'     => 'Firewall API',
    'system-audit-api.php' => 'System audit API',
    'log-viewer-api.php'   => 'Log viewer API',
    'tprfn-hub-report.php' => 'Hub health report',
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
    chk('Configuration','APRS call configured', !empty($cfg['station']['aprs_call']??''),
        $cfg['station']['aprs_call']??'NOT SET', 'Set station.aprs_call in config.php');
    chk('Configuration','APRS passcode configured', !empty($cfg['station']['aprs_pass']??''),
        !empty($cfg['station']['aprs_pass']??'') ? 'Set' : 'NOT SET',
        'Set station.aprs_pass — generate at https://apps.magicbug.co.uk/passcode/');
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
        $required = ['sessions','hubs','daily_summaries','prop_decisions'];
        foreach ($required as $t) {
            if (in_array($t,$tables)) {
                $cnt = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                chkPass('Database',"Table: $t", "$cnt rows");
            } else {
                chkFail('Database',"Table: $t", 'Missing',
                    "mysql -u{$db['user']} -p {$db['name']} < data/schema.sql");
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
$cfgPaths = ['/home/linbpq/bpq32.cfg','/home/tony/linbpq/bpq32.cfg',
             '/home/pi/linbpq/bpq32.cfg','/etc/linbpq/bpq32.cfg'];
$bpqCfg = null;
foreach ($cfgPaths as $p) { if (fileOk($p)) { $bpqCfg = $p; break; } }

if ($bpqCfg) {
    chkPass('LinBPQ','bpq32.cfg found', $bpqCfg);
    $bc = file_get_contents($bpqCfg);
    chk('LinBPQ','NODECALL set',    preg_match('/^NODECALL\s*=/mi',$bc)>0, '', 'Set NODECALL=YOURCALL-N');
    chk('LinBPQ','LINMAIL enabled', preg_match('/^LINMAIL\s*$/mi',$bc)>0,  '', 'Add LINMAIL to bpq32.cfg');
    chk('LinBPQ','LINCHAT enabled', preg_match('/^LINCHAT\s*$/mi',$bc)>0,  '', 'Add LINCHAT to bpq32.cfg');
    chk('LinBPQ','BBS APPLICATION', preg_match('/^APPLICATION.*BBS/mi',$bc)>0,  '', 'Add APPLICATION line for BBS');
    chk('LinBPQ','CHAT APPLICATION',preg_match('/^APPLICATION.*CHAT/mi',$bc)>0, '', 'Add APPLICATION line for CHAT');
    chkWarn('LinBPQ','TCPPORT in config', preg_match('/^TCPPORT\s*=/mi',$bc)>0,
        'TCPPORT setting in bpq32.cfg telnet port section',
        'Set TCPPORT=8010 in the PORT/DRIVER=TELNET section');
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
        chkFail('Services', "$label service file", 'Missing: '.$sf,
            'sudo cp '.basename($sf).' /etc/systemd/system/ && sudo systemctl daemon-reload');
    } else {
        $svc = file_get_contents($sf);
        chkOk('Services', "$label service file", $sf);
        // Check correct script path
        $scriptMap = [
            'bpq-chat.service'      => '/var/www/tprfn/scripts/bpq-chat-daemon.py',
            'bpq-aprs.service'      => '/var/www/tprfn/scripts/bpq-aprs-daemon.py',
        ];
        $bn = basename($sf);
        if (isset($scriptMap[$bn])) {
            $sp = $scriptMap[$bn];
            if (strpos($svc, $sp) === false)
                chkFail('Services', "$label ExecStart path", "Should point to $sp",
                    "Edit $sf: ExecStart=/usr/bin/python3 $sp");
            else
                chkOk('Services', "$label ExecStart path", $sp);
        }
        // www-data user
        if ($bn !== 'vara-validator.service') {
            $hasUser = strpos($svc,'www-data') !== false;
            if (!$hasUser)
                chkFail('Services', "$label User=www-data", 'Not set — daemon may lack cache write access',
                    "Add User=www-data Group=www-data to [Service] in $sf");
            else
                chkOk('Services', "$label User=www-data", 'Set correctly');
        }
        // Restart=always
        if (strpos($svc,'Restart=always') === false)
            chkWarn('Services', "$label Restart=always", 'Missing — daemon will not auto-restart on crash',
                "Add Restart=always to [Service] in $sf");
        else
            chkOk('Services', "$label Restart=always", 'Set');
    }
}

// ═══════════════════════════════════════════════════════════════════
// 11. CRON JOBS
// ═══════════════════════════════════════════════════════════════════
$rootCron = shell_exec('sudo crontab -l 2>/dev/null') ?: '';
$userCron = shell_exec('crontab -u '.get_current_user().' -l 2>/dev/null') ?: '';
$allCron  = $rootCron . $userCron . (shell_exec('cat /etc/cron.d/* 2>/dev/null') ?: '');

// connect-watchdog: must be in root only
$wdRoot = strpos($rootCron,'connect-watchdog') !== false;
$wdUser = strpos($userCron,'connect-watchdog') !== false;
if ($wdRoot && !$wdUser)
    chkOk('Cron Jobs','connect-watchdog (root cron, every 5 min)', 'Root only — correct');
elseif ($wdRoot && $wdUser)
    chkFail('Cron Jobs','connect-watchdog DUPLICATE RACE CONDITION',
        'In BOTH root and user crontab — causes state file conflicts, watchdog never fires!',
        'crontab -e   # remove connect-watchdog line from USER crontab, keep root only');
else
    chkFail('Cron Jobs','connect-watchdog not scheduled',
        'Watchdog will not monitor connections',
        '# Add to root crontab (sudo crontab -e):'."
".'*/5 * * * * /usr/bin/python3 /var/www/tprfn/scripts/connect-watchdog.py >> /var/log/connect-watchdog.log 2>&1');

// wp_manager auto-clean: 3am daily
$wpSched = strpos($allCron,'wp_manager') !== false && strpos($allCron,'auto-clean') !== false;
if ($wpSched) chkOk('Cron Jobs','wp_manager --auto-clean (3am daily)', 'Scheduled');
else chkWarn('Cron Jobs','wp_manager --auto-clean not scheduled',
    'WP.cfg will not be automatically cleaned',
    '# sudo crontab -e — add:'."
".'0 3 * * * cd /home/tony/linbpq && /usr/bin/python3 /var/www/tprfn/scripts/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1');

// prop-scheduler
if (strpos($allCron,'prop-scheduler') !== false)
    chkOk('Cron Jobs','prop-scheduler scheduled', 'Present in crontab');
else
    chkWarn('Cron Jobs','prop-scheduler not scheduled', 'May be run manually — OK if intended', '');

// storm-monitor
if (strpos($allCron,'storm-monitor') !== false)
    chkOk('Cron Jobs','storm-monitor scheduled', 'Present in crontab');
else
    chkWarn('Cron Jobs','storm-monitor not scheduled', 'Optional — needed for Kp-based suspend', '');

// Malicious cron check (from security audit — /boot/nnnn and sysmd.e)
$malicious = preg_match('/\/boot\/[0-9]+|sysmd\.e/', $allCron);
if ($malicious)
    chkFail('Cron Jobs','MALICIOUS CRON ENTRY DETECTED',
        'Possible backdoor — review ALL crontabs immediately',
        'sudo crontab -l && crontab -l && cat /etc/cron.d/*');
else
    chkOk('Cron Jobs','No malicious cron entries', 'Clean');

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
        chkOk('Log Files',"$label log", "${kb} KB, last modified {$age}m ago");
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
function showLogin() { ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BPQ Dashboard — Install Check</title>
<style>
body{margin:0;background:#0d1117;color:#e6edf3;font-family:system-ui,sans-serif;
     display:flex;align-items:center;justify-content:center;min-height:100vh;}
.box{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:40px;text-align:center;width:340px;}
h2{color:#4a9eff;margin:0 0 8px;}p{color:#8b949e;font-size:13px;margin:0 0 24px;}
input{width:100%;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#e6edf3;
      padding:10px 14px;font-size:14px;box-sizing:border-box;margin-bottom:12px;outline:none;}
input:focus{border-color:#4a9eff;}
button{width:100%;background:#4a9eff;border:none;border-radius:6px;color:#fff;
       padding:11px;font-size:14px;font-weight:700;cursor:pointer;}
button:hover{background:#6ab0ff;}
</style></head><body>
<div class="box">
<h2>🔧 BPQ Dashboard</h2>
<p>Post-Installation Check v1.5.5</p>
<form method="get">
<input type="password" name="pass" placeholder="Enter check password" autofocus>
<button type="submit">▶ Run Checks</button>
</form></div></body></html>
<?php }

// ── Main render ────────────────────────────────────────────────────
function renderPage($results,$pass,$fail,$warn,$score,$sc) {
$total = $pass+$fail+$warn; ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BPQ Dashboard v1.5.5 — Install Check</title>
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
<h1>🔧 BPQ Dashboard v1.5.5 — Post-Installation Check</h1>
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
    BPQ Dashboard v1.5.5 · Access: <?=htmlspecialchars("https://{$_SERVER['HTTP_HOST']}/install-check.php?pass=".CHECK_PASS)?>
</div>
</body></html>
<?php }
