<?php
/**
 * admin/maintenance-api.php — actions for the Maintenance page (v1.5.19)
 * Lives behind the /admin/ login; PHP checks that login too.
 *
 *   GET  ?action=whoami           the admin login name of this request
 *   GET  ?action=version_status   installed dashboard version vs the newest GitHub release
 *   GET  ?action=forms_status     installed PKTNET forms vs the author's latest
 *   POST ?action=forms_update     download the latest forms and swap them in
 *   POST ?action=forms_undo       put the previous copy back
 *   POST ?action=admin_password   change the logged-in admin's password
 *                                 body: {"current": "...", "new": "..."}
 *   GET  ?action=watch_get        System Logs: watched stations (+ last heard),
 *                                 live MHeard switch, BPQ ports (v1.5.26)
 *   GET  ?action=backup_status    the station's last backup (v1.5.34), from the
 *                                 status file a backup script writes
 *   POST ?action=watch_save       save them to data/system-logs.json
 *                                 body: {"watch": [{"call","port","hours"}], "live_mheard": bool}
 *
 * Every POST needs the header  X-BPQ-Dashboard: 1  (sent by the page; a
 * form or link on another web site can't add it, so a visitor's saved login
 * can't be used from elsewhere).
 *
 * PKTNET forms (N3MEL's TPRFN Forms Suite) are downloaded by
 * scripts/fetch-forms.sh as the web user; the replaced copy is kept outside
 * the web folder (/var/lib/bpq-dashboard/pktnet-forms.prev) for Undo.
 *
 * Admin password: the user is the one nginx / Apache checked for THIS
 * request (never taken from the form). The current password is checked
 * with htpasswd -v; the change is made by the root-owned helper
 * /usr/local/sbin/bpq-dashboard-set-admin-password through one sudo rule
 * (www-data-tools.sudoers). Passwords go to both on stdin, never on a
 * command line, and are never logged. 5 wrong current passwords from one
 * address in 15 minutes → 429.
 */
require_once dirname(__DIR__) . '/includes/web-login.php';
bpq_require_web_login('The Maintenance page');

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$WEB_ROOT  = dirname(__DIR__);
$FORMS     = "$WEB_ROOT/pktnet-forms";
$HTPASSWD  = '/etc/nginx/.bpq-mgmt-auth';
$HELPER    = '/usr/local/sbin/bpq-dashboard-set-admin-password';
$FAIL_DIR  = "$WEB_ROOT/cache/admin-password";
$FAIL_MAX  = 5;
$FAIL_WIN  = 900;

function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }

/** Run a command with optional stdin; returns [exit code, stdout, stderr]. */
function run(array $cmd, string $stdin = '', int $timeout = 120, array $env = []): array {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                   $env ? array_merge(['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'], $env) : null);
    if (!is_resource($p)) return [127, '', 'could not start ' . $cmd[0]];
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $o = ''; $e = ''; $t0 = time();
    while (true) {
        $o .= (string)stream_get_contents($pipes[1]); $e .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) break;
        if (time() - $t0 > $timeout) { proc_terminate($p); $e .= "\n(timed out)"; break; }
        usleep(100000);
    }
    $o .= (string)stream_get_contents($pipes[1]); $e .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    if (isset($st) && !$st['running'] && $st['exitcode'] >= 0) $rc = $st['exitcode'];
    return [$rc, $o, $e];
}

function prev_dir(string $webRoot): string {
    foreach (['/var/lib/bpq-dashboard', "$webRoot/cache"] as $d) {
        if (is_dir($d) && is_writable($d)) return $d;
    }
    return "$webRoot/cache";
}

function read_source(string $dir): array {
    $f = "$dir/.source";
    $r = [];
    if (is_readable($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (strpos($l, '=') !== false) { [$k, $v] = explode('=', $l, 2); $r[trim($k)] = trim($v); }
        }
    }
    return $r;
}

/** The author's newest commit: git ls-remote, else the GitHub API. Cached 1 h. */
function latest_commit(string $repo, string $cacheFile, bool $refresh): array {
    if (!$refresh && is_readable($cacheFile) && time() - filemtime($cacheFile) < 3600) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c)) return $c;
    }
    $res = ['commit' => '', 'date' => '', 'error' => ''];
    if (!preg_match('~^https://[A-Za-z0-9.-]+/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~', $repo)) {
        $res['error'] = 'repository address not recognised';
    } else {
        [$rc, $o] = run(['git', 'ls-remote', "$repo.git", 'HEAD'], '', 25, ['GIT_TERMINAL_PROMPT' => '0']);
        if ($rc === 0 && preg_match('/^([0-9a-f]{40})\s+HEAD/m', $o, $m)) {
            $res['commit'] = substr($m[1], 0, 12);
        } elseif (preg_match('~^https://github\.com/([^/]+)/([^/]+)$~', $repo, $m)) {
            $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: BPQ-Dashboard\r\nAccept: application/vnd.github+json\r\n"]]);
            $j = @file_get_contents("https://api.github.com/repos/{$m[1]}/{$m[2]}/commits?per_page=1", false, $ctx);
            $d = $j ? json_decode($j, true) : null;
            if (is_array($d) && !empty($d[0]['sha'])) {
                $res['commit'] = substr($d[0]['sha'], 0, 12);
                $res['date'] = (string)($d[0]['commit']['committer']['date'] ?? '');
            } else {
                $res['error'] = "can't reach $repo from this server (outbound HTTPS to GitHub blocked?)";
            }
        } else {
            $res['error'] = "can't reach $repo from this server";
        }
    }
    @mkdir(dirname($cacheFile), 0750, true);
    @file_put_contents($cacheFile, json_encode($res), LOCK_EX);
    return $res;
}

function forms_status(string $forms, string $webRoot, bool $refresh): array {
    $src  = read_source($forms);
    $repo = $src['source'] ?? 'https://github.com/N3MEL39/TPRFN_HTML_Forms';
    $prev = prev_dir($webRoot) . '/pktnet-forms.prev';
    $latest = latest_commit($repo, "$webRoot/cache/forms-latest.json", $refresh);
    $installed = is_file("$forms/index.html");
    return [
        'ok'          => true,
        'installed'   => $installed,
        'source'      => $repo,
        'fetched'     => $src['fetched'] ?? '',
        'commit'      => $src['commit'] ?? '',
        'commit_date' => $src['commit_date'] ?? '',
        'pages'       => $installed ? count(glob("$forms/*.html") ?: []) + count(glob("$forms/*/*.html") ?: []) : 0,
        'latest'      => $latest,
        'up_to_date'  => $installed && ($src['commit'] ?? '') !== '' && ($src['commit'] ?? '') === $latest['commit'],
        'can_undo'    => is_file("$prev/index.html"),
        'prev_fetched'=> read_source($prev)['fetched'] ?? '',
        'writable'    => is_writable($webRoot) && (!file_exists($forms) || is_writable($forms)),
    ];
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (($_SERVER['HTTP_X_BPQ_DASHBOARD'] ?? '') !== '1') fail('Missing request header', 403);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        fail('Request from another site refused', 403);
    }
}

switch ($action) {
case 'whoami':   // the admin login of this request (for the page's labels)
    out(['ok' => true, 'user' => bpq_web_login_user()]);

case 'version_status':   // v1.5.19: installed version vs the newest release tag on GitHub
    $vf = "$WEB_ROOT/VERSION";
    $inst = is_readable($vf) ? trim((string)file_get_contents($vf)) : '';
    $how = $inst !== '' ? 'VERSION file' : '';
    if ($inst === '' && preg_match('/BPQ Dashboard v(\d+\.\d+\.\d+)/', (string)@file_get_contents("$WEB_ROOT/install-check.php", false, null, 0, 400), $m)) {
        $inst = $m[1]; $how = 'from install-check.php; no VERSION file before v1.5.19';
    }
    $cache = "$WEB_ROOT/cache/release-latest.json";
    $lat = null;
    if (empty($_GET['refresh']) && is_readable($cache) && time() - filemtime($cache) < 6 * 3600) $lat = json_decode((string)file_get_contents($cache), true);
    if (!is_array($lat)) {
        $lat = ['latest' => '', 'error' => ''];
        [$rc, $o] = run(['git', 'ls-remote', '--tags', '--refs', 'https://github.com/bpq-dashboard/bpq-dashboard.git'], '', 25, ['GIT_TERMINAL_PROMPT' => '0']);
        $tags = [];
        if ($rc === 0 && preg_match_all('~refs/tags/v(\d+\.\d+\.\d+)\s*$~m', $o, $mm)) $tags = $mm[1];
        if (!$tags) {
            $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: BPQ-Dashboard\r\nAccept: application/vnd.github+json\r\n"]]);
            $j = @file_get_contents('https://api.github.com/repos/bpq-dashboard/bpq-dashboard/tags?per_page=50', false, $ctx);
            foreach ((json_decode((string)$j, true) ?: []) as $t) if (preg_match('/^v(\d+\.\d+\.\d+)$/', (string)($t['name'] ?? ''), $m)) $tags[] = $m[1];
        }
        if ($tags) { usort($tags, 'version_compare'); $lat['latest'] = end($tags); }
        else $lat['error'] = "can't reach GitHub from this server";
        @file_put_contents($cache, json_encode($lat), LOCK_EX);
    }
    out(['ok' => true, 'installed' => $inst, 'installed_how' => $how, 'latest' => $lat['latest'], 'error_latest' => $lat['error'],
         'update_available' => $inst !== '' && $lat['latest'] !== '' && version_compare($lat['latest'], $inst, '>')]);

case 'forms_status':
    out(forms_status($FORMS, $WEB_ROOT, !empty($_GET['refresh'])));

case 'forms_update':
    if ($method !== 'POST') fail('POST only', 405);
    $st = forms_status($FORMS, $WEB_ROOT, false);
    if (!$st['writable']) fail("The web server can't write $FORMS (or the web folder). Fix: sudo chown -R www-data:www-data $FORMS, or update over SSH: sudo bash $WEB_ROOT/scripts/fetch-forms.sh --force", 500);
    $lock = fopen("$WEB_ROOT/cache/forms-update.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail('An update is already running', 409);
    [$rc, $o, $e] = run(['bash', "$WEB_ROOT/scripts/fetch-forms.sh", $WEB_ROOT, '--force'], '', 240,
                        ['FORMS_REPO' => $st['source'], 'FORMS_PREV_DIR' => prev_dir($WEB_ROOT), 'HOME' => sys_get_temp_dir()]);
    @unlink("$WEB_ROOT/cache/forms-latest.json");
    if ($rc !== 0) fail('Update failed; the forms you had are unchanged. ' . trim($e ?: $o), 502);
    out(['ok' => true, 'message' => trim($o), 'status' => forms_status($FORMS, $WEB_ROOT, true)]);

case 'forms_undo':
    if ($method !== 'POST') fail('POST only', 405);
    $prev = prev_dir($WEB_ROOT) . '/pktnet-forms.prev';
    if (!is_file("$prev/index.html")) fail('No previous copy to put back', 404);
    $tmp = "$WEB_ROOT/.pktnet-forms.undo-" . getmypid();
    $okSwap = (!file_exists($FORMS) || @rename($FORMS, $tmp)) && @rename($prev, $FORMS);
    if (!$okSwap) {
        // rename() can't cross filesystems: copy instead
        [$rc] = run(['cp', '-a', "$prev/.", $FORMS . '.restore']);
        if ($rc === 0 && (!file_exists($FORMS) || @rename($FORMS, $tmp)) && @rename($FORMS . '.restore', $FORMS)) {
            run(['rm', '-rf', $prev]); $okSwap = true;
        }
    }
    if (!$okSwap) { if (is_dir($tmp) && !file_exists($FORMS)) @rename($tmp, $FORMS); fail('Could not put the previous copy back', 500); }
    if (is_dir($tmp)) run(['mv', $tmp, $prev]);   // the copy just replaced becomes the new "previous"
    out(['ok' => true, 'message' => 'Previous forms put back', 'status' => forms_status($FORMS, $WEB_ROOT, false)]);

case 'admin_password':
    if ($method !== 'POST') fail('POST only', 405);
    $user = bpq_web_login_user();
    if ($user === '') fail('The web server did not pass the login name; change it over SSH: sudo htpasswd -B ' . $HTPASSWD . ' <user>', 500);
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $cur = (string)($in['current'] ?? ''); $new = (string)($in['new'] ?? '');
    // throttle wrong current passwords
    @mkdir($FAIL_DIR, 0750, true);
    $ff = $FAIL_DIR . '/' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')) . '.json';
    $fs = is_readable($ff) ? (json_decode((string)file_get_contents($ff), true) ?: []) : [];
    if (!$fs || time() - (int)($fs['first'] ?? 0) >= $FAIL_WIN) $fs = ['count' => 0, 'first' => time()];
    if ($fs['count'] >= $FAIL_MAX) fail('Too many wrong passwords. Try again in ' . max(1, (int)ceil(($FAIL_WIN - (time() - $fs['first'])) / 60)) . ' minutes.', 429);
    if (strlen($new) < 12 || strlen($new) > 128) fail('The new password must be 12 to 128 characters.');
    if (strpos($new, ':') !== false || preg_match('/[\x00-\x1f\x7f]/', $new)) fail("The new password can't contain ':' or control characters.");
    if ($new === $cur) fail('The new password is the same as the current one.');
    if (!is_readable($HTPASSWD)) fail("The web server can't read $HTPASSWD", 500);
    [$rc] = run(['htpasswd', '-v', '-i', $HTPASSWD, $user], $cur . "\n", 15);
    if ($rc !== 0) {
        $fs['count']++; @file_put_contents($ff, json_encode($fs), LOCK_EX);
        fail('The current password is wrong.', 403);
    }
    @unlink($ff);
    if (!is_executable($HELPER)) fail("The password helper isn't installed ($HELPER). Re-run install.sh's sudo-rules step, or change it over SSH: sudo htpasswd -B $HTPASSWD $user", 501);
    [$rc, $o, $e] = run(['sudo', '-n', $HELPER, $user], $new . "\n", 30);
    if ($rc !== 0) {
        $why = trim($e ?: $o);
        if (stripos($why, 'password is required') !== false || stripos($why, 'not allowed') !== false || stripos($why, 'sudo') === 0)
            $why = 'the sudo rule for the helper is missing (/etc/sudoers.d/www-data-tools)';
        fail("Not changed: $why. Over SSH instead: sudo htpasswd -B $HTPASSWD $user", 500);
    }
    if (function_exists('dashboardLog')) dashboardLog('info', "admin password changed for $user");
    out(['ok' => true, 'message' => "Password changed for $user. Your browser will ask you to log in again with the new password."]);

case 'backup_status':   // v1.5.42
    require_once dirname(__DIR__) . '/includes/station-config.php';
    out(['ok' => true] + bpq_backup_status());

case 'watch_get':   // v1.5.28
case 'watch_save':
    require_once dirname(__DIR__) . '/includes/station-config.php';
    $file = bpq_system_logs_file();
    if ($action === 'watch_save') {
        if ($method !== 'POST') fail('POST only', 405);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in) || !is_array($in['watch'] ?? null)) fail('Bad request');
        if (count($in['watch']) > 20) fail('At most 20 watched stations');
        foreach ($in['watch'] as $w) {
            $c = strtoupper(trim((string)($w['call'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{1,6}(-\d{1,2})?$/', $c)) fail('Not a callsign: ' . substr($c, 0, 12));
            $h = (float)($w['hours'] ?? 0);
            if ($h < 0.5 || $h > 720) fail("Hours for $c: 0.5 to 720");
        }
        $save = bpq_system_logs_saved() ?? [];
        $save['watch'] = bpq_watch_list($in['watch']);
        $save['live_mheard'] = (bool)($in['live_mheard'] ?? true);
        $save['saved_by'] = bpq_web_login_user();
        $save['saved_at'] = gmdate('Y-m-d H:i:s') . 'Z';
        $dir = dirname($file);
        if (!is_dir($dir) || !is_writable($dir)) fail("The web server can't write $dir. Over SSH: sudo chown www-data " . $dir, 500);
        $tmp = $file . '.tmp' . getmypid();
        if (@file_put_contents($tmp, json_encode($save, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp); fail('Could not save ' . basename($file), 500);
        }
        @chmod($file, 0640);
        if (function_exists('dashboardLog')) dashboardLog('info', 'System Logs watch list saved by ' . $save['saved_by']);
    }
    $pdo = null;
    if (is_file(dirname(__DIR__) . '/tprfn-db.php')) {
        try { require_once dirname(__DIR__) . '/tprfn-db.php'; $pdo = tprfn_db(); } catch (Throwable $e) { $pdo = null; }
    }
    $ports = [];
    foreach (bpq_linbpq_ports() as $n => $p) $ports[] = ['port' => $n, 'name' => $p['name']];
    $cfg = bpq_config_section('system_logs');
    $saved = bpq_system_logs_saved();
    out(['ok' => true,
         'message' => $action === 'watch_save' ? 'Saved. System Logs and RF Connections use it from their next refresh.' : null,
         'watch' => bpq_watch_status($pdo),
         'source' => bpq_watch_source(),
         'live_mheard' => bpq_live_mheard_on(),
         'live_mheard_config_off' => array_key_exists('live_mheard', $cfg) && !$cfg['live_mheard'],
         'ports' => $ports,
         'db' => $pdo !== null,
         'saved_by' => $saved['saved_by'] ?? null, 'saved_at' => $saved['saved_at'] ?? null]);

default:
    fail('Unknown action');
}
