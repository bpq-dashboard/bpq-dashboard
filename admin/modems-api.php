<?php
/**
 * Admin > Modems (v1.5.97, K1AJD): the modems' own displays, watch-only.
 *
 * GET  modems-api.php?action=overview          what this station has, and how each is doing
 * GET  modems-api.php?action=snap&d=:3&id=0x…  PNG of one modem window (VARA HF, flrig, QtSoundModem …)
 * GET  modems-api.php?action=mercury&since=N   Mercury's waterfall rows + status (from the relay)
 * GET  modems-api.php?action=data2g&since=N    Data2G's waterfall rows, link, bursts (header X-Data2G-Session)
 * POST modems-api.php?action=d2g_login         {password} -> Data2G monitor session (kept in this browser only)
 * POST modems-api.php?action=d2g_logout        {token}
 *
 * Nothing here can change a modem:
 * - window pictures come from the read-only root helper
 *   /usr/local/sbin/bpq-dashboard-modem-view (tools/modem-view-helper), which
 *   only reads a window's pixels;
 * - Mercury and Data2G come through bpq-modem-relay (127.0.0.1:8769), which
 *   never forwards a command to them. Their own monitor pages also take
 *   commands, so the browser is never connected to them directly.
 * The Data2G password is passed to Data2G once and not stored anywhere;
 * the session token it returns stays in the viewer's browser tab.
 */
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/web-login.php';
bpq_require_web_login('Modems');
require_once dirname(__DIR__) . '/includes/station-config.php';
require_once dirname(__DIR__) . '/includes/mercury-status.php';
require_once dirname(__DIR__) . '/includes/data2g-status.php';

const HELPER = '/usr/local/sbin/bpq-dashboard-modem-view';
$RELAY_PORT = (int)(bpq_config_section('modems')['relay_port'] ?? 8769);
$CACHE = dirname(__DIR__) . '/cache';

function out(array $a, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($a);
    exit;
}

$action = (string)($_GET['action'] ?? 'overview');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    if (($_SERVER['HTTP_X_BPQ_DASHBOARD'] ?? '') !== '1') out(['ok' => false, 'error' => 'Missing request header'], 403);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        out(['ok' => false, 'error' => 'Request from another site refused'], 403);
    }
}

/** The helper's answer, or why there is none. Tests: MODEMS_TEST_HELPER stands in for it. */
function helper(string $args, bool $binary = false): array {
    $test = (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') ? (string)getenv('MODEMS_TEST_HELPER') : '';
    if ($test !== '') {
        $cmd = 'python3 ' . escapeshellarg($test) . ' ' . $args;
    } elseif (!is_file(HELPER)) {
        return [null, 'missing'];
    } else {
        $cmd = 'sudo -n ' . HELPER . ' ' . $args;
    }
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return [null, 'failed'];
    $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    if ($rc !== 0) {
        if (preg_match('/password is required|not allowed|may not run sudo|^sudo:/m', $e)) return [null, 'sudo_refused'];
        return [null, trim($e) !== '' ? mb_substr(trim($e), 0, 200) : "helper exit $rc"];
    }
    return [$o, ''];
}

function relay(string $path, array $headers = []): array {
    global $RELAY_PORT;
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true,
        'header' => implode("\r\n", $headers)]]);
    $raw = @file_get_contents("http://127.0.0.1:$RELAY_PORT$path", false, $ctx);
    if ($raw === false) return ['ok' => false, 'relay' => 'down',
        'error' => 'Nothing answers on 127.0.0.1:' . $RELAY_PORT . ' (bpq-modem-relay.socket): sudo systemctl enable --now bpq-modem-relay.socket'];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : ['ok' => false, 'error' => 'The modem relay gave no answer'];
}

/** A small HTTP POST to Data2G's own page on 127.0.0.1 (login / logout). */
function d2g_post(int $port, string $path, array $body, string $cookie = ''): array {
    $hdr = "Content-Type: application/json\r\nHost: 127.0.0.1:$port";
    if ($cookie !== '') $hdr .= "\r\nCookie: data2g_session=$cookie";
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 5, 'ignore_errors' => true,
        'header' => $hdr, 'content' => json_encode($body)]]);
    $raw = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $code = 0; $tok = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
        if (preg_match('/^Set-Cookie:\s*data2g_session=([0-9a-f]{16,128});/i', $h, $m)) $tok = $m[1];
    }
    return [$code, $tok, $raw === false ? null : json_decode((string)$raw, true)];
}

/** Sound-modem programs running here: [{pid, name, args, user, since}] (from /proc, no sudo). */
function soundmodem_procs(): array {
    $out = [];
    foreach ((array)@scandir('/proc') as $pid) {
        if (!ctype_digit((string)$pid)) continue;
        $comm = trim((string)@file_get_contents("/proc/$pid/comm"));
        if (!preg_match('/^(QtSoundModem|soundmodem|direwolf|ardopc|ardop)/i', $comm)) continue;
        $args = array_values(array_filter(explode("\0", (string)@file_get_contents("/proc/$pid/cmdline")), 'strlen'));
        $start = null;
        $stat = (string)@file_get_contents("/proc/$pid/stat");
        $up = (string)@file_get_contents('/proc/uptime');
        if ($stat !== '' && $up !== '' && preg_match('/\)\s+(.*)$/s', $stat, $m)) {
            $f = preg_split('/\s+/', $m[1]);
            $ticks = (int)($f[19] ?? 0);                    // starttime, field 22
            $start = time() - (int)((float)explode(' ', $up)[0] - $ticks / 100);
        }
        $uid = @fileowner("/proc/$pid");
        $user = $uid !== false && function_exists('posix_getpwuid') ? ((posix_getpwuid($uid) ?: [])['name'] ?? (string)$uid) : '';
        $name = basename($args[0] ?? $comm);
        $out[] = ['pid' => (int)$pid, 'name' => $name, 'args' => implode(' ', array_map(fn($x) => mb_substr($x, 0, 60), array_slice($args, 1, 6))),
                  'user' => $user, 'since' => $start ? gmdate('Y-m-d H:i:s', $start) . 'Z' : null,
                  'gui' => !preg_grep('/^-?nogui$/i', array_slice($args, 1))];
    }
    return $out;
}

/** LinBPQ's KISS-over-TCP ports with their modem's state and the stations heard today. */
function kiss_modems(?array $socks): array {
    $ports = array_filter(bpq_linbpq_ports(), fn($p) => !empty($p['kiss_port']));
    if (!$ports) return [];
    $procs = soundmodem_procs();
    $pdo = null;
    if (is_file(dirname(__DIR__) . '/tprfn-db.php')) {
        try { require_once dirname(__DIR__) . '/tprfn-db.php'; $pdo = tprfn_db(); } catch (Throwable $e) { $pdo = null; }
    }
    $since = gmdate('Y-m-d 00:00:00');
    $out = [];
    foreach ($ports as $num => $p) {
        $kp = (int)$p['kiss_port'];
        $local = in_array(strtolower($p['kiss_host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true);
        $listening = $socks === null || !$local ? null : (bool)array_filter($socks, fn($x) => $x['state'] === '0A' && $x['lport'] === $kp);
        $linked = $socks === null ? null : (bool)array_filter($socks, fn($x) => $x['state'] === '01' && $x['rport'] === $kp);
        $status = $socks === null ? 'unknown' : ($linked ? 'up' : ($listening === false ? 'down' : ($local ? 'nolink' : 'remote')));
        $heard = []; $last = null;
        if ($pdo) {
            try {
                $st = $pdo->prepare('SELECT callsign, heard_at, status FROM net_mheard WHERE port = ? ORDER BY heard_at DESC LIMIT 1');
                $st->execute([$num]);
                $last = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                $st = $pdo->prepare('SELECT callsign, heard_at, status FROM net_mheard WHERE port = ? AND heard_at >= ? ORDER BY heard_at DESC LIMIT 40');
                $st->execute([$num, $since]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $heard[] = ['call' => $r['callsign'], 'at' => $r['heard_at'], 'connected' => $r['status'] === 'connected'];
            } catch (Throwable $e) { /* System Logs tables not there yet */ }
        }
        $out[] = ['port' => $num, 'name' => $p['name'], 'kiss' => ($p['kiss_host'] ?? '?') . ':' . $kp, 'local' => $local,
                  'status' => $status, 'programs' => $local ? $procs : [],
                  'last_call' => $last['callsign'] ?? null, 'last_heard' => $last['heard_at'] ?? null,
                  'heard_today' => $heard, 'db' => $pdo !== null];
    }
    return $out;
}

switch ($action) {

case 'overview':
    $o = ['ok' => true, 'checked_at' => gmdate('Y-m-d H:i:s') . 'Z'];
    // Windows on the station's desktop(s)
    [$raw, $err] = helper('list');
    if ($raw === null) {
        $o['windows'] = ['ok' => false, 'helper' => $err, 'error' => match ($err) {
            'missing'      => 'The read-only helper ' . HELPER . " isn't installed. Run the update again (sudo bash update.sh) and answer yes to the admin pages' sudo rules.",
            'sudo_refused' => "The web server isn't allowed to run " . HELPER . ': its sudo rule is missing. Run the update again and answer yes to the admin pages\' sudo rules.',
            default        => 'The helper failed: ' . $err,
        }];
    } else {
        $d = json_decode($raw, true);
        $o['windows'] = is_array($d) ? $d : ['ok' => false, 'error' => 'The helper gave no answer'];
    }
    // LinBPQ's VARA-type ports and whether LinBPQ is attached to each modem
    $socks = bpq_mercury_sockets();
    $m = bpq_mercury_status($CACHE);
    $g = bpq_data2g_status($CACHE);
    $mPort = !empty($m['enabled']) ? (int)$m['tnc_port'] : 0;
    $gPort = !empty($g['enabled']) ? (int)$g['tnc_port'] : 0;
    $vara = [];
    foreach (bpq_linbpq_ports() as $num => $bp) {
        if (($bp['driver'] ?? '') !== 'VARA' || empty($bp['tnc_port'])) continue;
        $p = (int)$bp['tnc_port'];
        if ($p === $mPort || $p === $gPort) continue;      // Mercury and Data2G have their own panels
        $local = in_array(strtolower($bp['tnc_host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true);
        $vara[] = ['num' => $num, 'name' => $bp['name'], 'host' => $local ? 'this computer' : 'another computer',
            'tnc_port' => $p, 'local' => $local,
            'listening' => $socks === null || !$local ? null : (bool)array_filter($socks, fn($x) => $x['state'] === '0A' && $x['lport'] === $p),
            'attached'  => $socks === null || !$local ? null : (bool)array_filter($socks, fn($x) => $x['state'] === '01' && ($x['lport'] === $p || $x['rport'] === $p))];
    }
    $o['vara_ports'] = $vara;
    $ms = bpq_mercury_settings();
    $o['mercury'] = empty($m['enabled']) ? ['enabled' => false] : [
        'enabled' => true, 'status' => $m['status'], 'running' => $m['running'], 'attached' => $m['attached'],
        'bpq_port' => $m['bpq_port'], 'session' => $m['session'], 'version' => $m['version'],
        'monitor' => ['on' => (bool)$ms['ui_enabled'], 'port' => (int)$ms['ui_port']]];
    $o['data2g'] = empty($g['enabled']) ? ['enabled' => false] : [
        'enabled' => true, 'status' => $g['status'], 'running' => $g['running'], 'attached' => $g['attached'],
        'bpq_port' => $g['bpq_port'], 'session' => $g['session'] ?? null, 'version' => $g['version'] ?? '',
        'monitor' => $g['monitor']];
    // v1.5.99: sound modems on LinBPQ's KISS-over-TCP ports (QtSoundModem,
    // Dire Wolf ...): often run without a window (QtSoundModem nogui), so
    // they get a status panel: the program, LinBPQ's link, stations heard.
    $o['kiss'] = kiss_modems($socks);
    $h = relay('/health');
    $o['relay'] = ['up' => !empty($h['ok']), 'error' => $h['error'] ?? ''];
    out($o);

case 'snap':
    $disp = (string)($_GET['d'] ?? ''); $id = strtolower((string)($_GET['id'] ?? ''));
    if (!preg_match('/^:\d{1,3}$/', $disp) || !preg_match('/^0x[0-9a-f]{1,8}$/', $id)) out(['ok' => false, 'error' => 'bad window'], 400);
    // one picture a second per window, however many people are watching
    $f = sys_get_temp_dir() . '/bpq-modem-view-' . substr($disp, 1) . '-' . $id . '.png';
    if (!is_file($f) || filemtime($f) < time() - 1 || filesize($f) === 0) {
        [$png, $err] = helper('snap ' . escapeshellarg($disp) . ' ' . escapeshellarg($id), true);
        if ($png === null || strncmp($png, "\x89PNG", 4) !== 0) {
            out(['ok' => false, 'error' => $err === 'the window is minimised' ? 'minimised' : ($err ?: 'no picture')], 502);
        }
        @file_put_contents($f . '.tmp', $png) && @rename($f . '.tmp', $f);
    } else {
        $png = (string)file_get_contents($f);
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    echo $png;
    exit;

case 'mercury':
    $ms = bpq_mercury_settings();
    if (!$ms['ui_enabled']) out(['ok' => false, 'error' => 'monitor_off']);
    out(relay('/mercury?port=' . (int)$ms['ui_port'] . '&since=' . max(0, (int)($_GET['since'] ?? 0))));

case 'data2g':
    $gs = bpq_data2g_settings();
    if ($gs['web_port'] <= 0) out(['ok' => false, 'error' => 'monitor_off']);
    $tok = (string)($_SERVER['HTTP_X_DATA2G_SESSION'] ?? '');
    if (!preg_match('/^[0-9a-f]{16,128}$/', $tok)) $tok = '';
    out(relay('/data2g?port=' . (int)$gs['web_port'] . '&since=' . max(0, (int)($_GET['since'] ?? 0)),
        $tok !== '' ? ["X-Data2G-Session: $tok"] : []));

case 'd2g_login':
    if ($method !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);
    $gs = bpq_data2g_settings();
    if ($gs['web_port'] <= 0) out(['ok' => false, 'error' => 'monitor_off']);
    $in = json_decode((string)file_get_contents('php://input'), true);
    $pw = is_array($in) ? (string)($in['password'] ?? '') : '';
    if ($pw === '' || strlen($pw) > 200) out(['ok' => false, 'error' => 'Type the Data2G monitor password']);
    [$code, $tok] = d2g_post((int)$gs['web_port'], '/api/login', ['password' => $pw]);
    if ($code === 200 && $tok !== '') out(['ok' => true, 'token' => $tok]);
    out(['ok' => false, 'error' => match ($code) {
        401 => 'Wrong password (the one set with tools/data2g-monitor.sh)',
        429 => 'Wait a second and try again',
        503 => 'Data2G has no monitor password yet: sudo bash tools/data2g-monitor.sh --password',
        0   => "Data2G's monitor isn't answering on port " . (int)$gs['web_port'],
        default => "Data2G answered $code",
    }]);

case 'd2g_logout':
    if ($method !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);
    $gs = bpq_data2g_settings();
    $in = json_decode((string)file_get_contents('php://input'), true);
    $tok = is_array($in) ? (string)($in['token'] ?? '') : '';
    if ($gs['web_port'] > 0 && preg_match('/^[0-9a-f]{16,128}$/', $tok)) d2g_post((int)$gs['web_port'], '/api/logout', [], $tok);
    out(['ok' => true]);

default:
    out(['ok' => false, 'error' => 'unknown action'], 400);
}
