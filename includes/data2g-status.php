<?php
/**
 * includes/data2g-status.php — Data2G HF modem status (BPQ Dashboard v1.5.66)
 *
 * Data2G (open-source HF modem, github.com/arodland/Data2G) offers a
 * VARA-style TNC that LinBPQ attaches to as a VARA port, like Mercury. Its
 * command port takes one client and LinBPQ holds it, so the dashboard never
 * talks to Data2G; the status comes from outside:
 *   - the process (/proc/<pid>/comm "data2g-host", or "data2g-web" when its
 *     monitor page is on) and its TNC sockets (/proc/net/tcp),
 *   - the data2g service file (ports: --command-port, --kiss-port, --web-port),
 *   - Data2G's log (the service appends its output to it), read
 *     incrementally: cache/data2g-state.json keeps the file's inode and
 *     offset plus what was found (callsigns, LISTEN, last transmit, last
 *     burst heard and its S/N, calls, sessions, audio problems, errors).
 *
 * Settings, config.php (all optional):
 *   'data2g' => [
 *       'enabled'  => 'auto',   // auto: show when Data2G's log, service or TNC is there; true; false
 *       'log'      => '/var/log/data2g/data2g.log',
 *       'unit'     => '/etc/systemd/system/data2g.service',  // read for the ports
 *       'tnc_port' => 8500,     // default: --command-port in the unit, else 8300 (Data2G's default)
 *       'in_headlines' => false,
 *   ],
 * Data2G's log has no version line: the version is read from
 * /usr/local/share/data2g/VERSION, which the install and monitor scripts write.
 * /includes/ is 403 on the web; PHP require only.
 */

require_once __DIR__ . '/station-config.php';
require_once __DIR__ . '/mercury-status.php';     // bpq_mercury_sockets()

function bpq_data2g_settings(): array {
    $c    = bpq_config_section('data2g');
    $unit = (string)($c['unit'] ?? '/etc/systemd/system/data2g.service');
    $port = isset($c['tnc_port']) ? (int)$c['tnc_port'] : 0;
    $u = ['command' => 0, 'kiss' => 0, 'web' => 0, 'web_listen' => '', 'exec' => '', 'tz' => ''];
    if (@is_readable($unit)) {
        $txt = (string)@file_get_contents($unit);
        $txt = preg_replace('/\\\\\r?\n\s*/', ' ', $txt);           // join "\" continuation lines
        if (preg_match('/^\s*ExecStart\s*=\s*(.+)$/m', $txt, $m)) {
            $u['exec'] = trim($m[1]);
            if (preg_match('/--command-port[ =](\d+)/', $m[1], $x)) $u['command'] = (int)$x[1];
            if (preg_match('/--kiss-port[ =](\d+)/', $m[1], $x))    $u['kiss'] = (int)$x[1];
            if (preg_match('/\bdata2g-web\b/', $m[1])) {
                $u['web'] = preg_match('/--web-port[ =](\d+)/', $m[1], $x) ? (int)$x[1] : 8400;
                $u['web_listen'] = preg_match('/--web-listen[ =](\S+)/', $m[1], $x) ? $x[1] : '127.0.0.1';
            }
        }
        if (preg_match('/^\s*Environment\s*=.*\bTZ=(\S+)/m', $txt, $m)) $u['tz'] = trim($m[1], '"');
    }
    if ($port <= 0) $port = $u['command'];
    $en = $c['enabled'] ?? 'auto';
    if (is_string($en)) $en = strtolower($en) === 'auto' ? 'auto' : filter_var($en, FILTER_VALIDATE_BOOLEAN);
    return [
        'enabled'   => $en,
        'log'       => (string)($c['log'] ?? '/var/log/data2g/data2g.log'),
        'unit'      => $unit,
        'unit_there'=> @file_exists($unit),
        'tnc_port'  => $port > 0 ? $port : 8300,
        // v1.5.64: 8300 is also VARA's port, so a LinBPQ port on it only means
        // Data2G when the port came from config.php or Data2G's own unit file
        'tnc_port_known' => $port > 0,
        'kiss_port' => $u['kiss'] > 0 ? $u['kiss'] : 8100,      // Data2G's default; clashes with QtSoundModem's KISS port
        'web_port'  => $u['web'],                                // 0: the monitor page is off (data2g-host)
        'web_listen'=> $u['web_listen'],
        'tz'        => $u['tz'],
        'version_file' => (string)($c['version_file'] ?? '/usr/local/share/data2g/VERSION'),
    ];
}

/** Which Data2G program is running: 'data2g-web', 'data2g-host', '' none; null when /proc can't be read. */
function bpq_data2g_process(): ?string {
    $dirs = @glob('/proc/[0-9]*', GLOB_ONLYDIR);
    if (!$dirs) return null;
    foreach ($dirs as $d) {
        $n = trim((string)@file_get_contents("$d/comm"));
        if ($n === 'data2g-host' || $n === 'data2g-web') return $n;
    }
    return '';
}

function bpq_data2g_fresh_state(): array {
    return ['ino' => 0, 'offset' => 0, 'mycall' => null, 'started_at' => null, 'web_url' => null,
            'client' => null, 'client_at' => null, 'last_listen_at' => null, 'listen' => null, 'bw' => null,
            'last_tx_at' => null, 'last_rx' => null, 'audio_ok' => true, 'audio_changed_at' => null,
            'calling' => null, 'pending_in' => null, 'session' => null, 'sessions' => [], 'events' => [],
            'errors' => [], 'warnings' => [], 'calls' => [], 'last_stats' => null];
}

/** Feed one log line into the state. */
function bpq_data2g_line(array &$s, string $line): void {
    // 2026-11-15 20:33:10,578 INFO command: MYCALL N0CALL-4 N0CALL-5 N0CALL-7 N0CALL-10
    if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?:[,.]\d+)?\s+([A-Z]+)\s+(.*)$/', $line, $m)) return;
    [, $d, $lvl, $msg] = $m;
    $t = $d;     // as written: the service runs with TZ=UTC (a UTC server clock does the same)
    // the busiest lines first: LinBPQ toggles LISTEN as it scans, and every burst is a line
    if (strpos($msg, 'command: LISTEN ') === 0) {
        $s['last_listen_at'] = $t; $s['listen'] = trim(substr($msg, 16)) === 'ON'; return;
    }
    if (preg_match('/^(TX|RX) b\d+ (\w+) (\S+) x\d+/', $msg, $b)) {
        if ($b[1] === 'TX') { $s['last_tx_at'] = $t; return; }
        if (preg_match('/\| snr (-?[\d.]+) dB/', $msg, $x)) {
            $snr = round((float)$x[1], 1);
            $s['last_rx'] = ['at' => $t, 'snr' => $snr, 'mode' => $b[3]];
            if ($s['session']) {
                $z = &$s['session'];
                $z['bursts']++; $z['snr_last'] = $snr;
                $z['snr_min'] = $z['snr_min'] === null ? $snr : min($z['snr_min'], $snr);
                $z['snr_max'] = $z['snr_max'] === null ? $snr : max($z['snr_max'], $snr);
                if ($b[2] === 'data') $z['mode'] = $b[3];
                unset($z);
            }
        }
        return;
    }
    if (strpos($msg, 'TX ') === 0 && strpos($msg, 'TX audio') !== 0) { $s['last_tx_at'] = $t; }   // CONNECT, DISC, ID ... keyed the radio too

    $call = function (string $dir, string $peer, string $outcome) use (&$s, $t) {
        $s['calls'][] = ['t' => $t, 'dir' => $dir, 'peer' => $peer, 'outcome' => $outcome];
        if (count($s['calls']) > 300) $s['calls'] = array_slice($s['calls'], -300);
    };
    $ev = function (string $kind, string $text) use (&$s, $t) {
        $s['events'][] = ['t' => $t, 'kind' => $kind, 'text' => mb_substr($text, 0, 160)];
        if (count($s['events']) > 30) $s['events'] = array_slice($s['events'], -30);
    };

    if (strpos($msg, 'commands on ') === 0) {
        $s['started_at'] = $t; $s['session'] = null; $s['calling'] = null; $s['pending_in'] = null;
        $s['audio_ok'] = true; $s['web_url'] = null; $s['client'] = null;
        $ev('start', 'Data2G started'); return;
    }
    if (preg_match('/^web UI on (\S+)/', $msg, $x)) { $s['web_url'] = $x[1]; return; }
    if ($msg === 'shutting down') { $ev('stop', 'Data2G stopped'); $s['client'] = null; return; }
    if (preg_match('/^client \S+ on port (\d+)/', $msg, $x)) {
        if (!$s['client']) $ev('link', 'LinBPQ attached');
        $s['client'] = true; $s['client_at'] = $t; return;
    }
    if (strpos($msg, 'command client gone') === 0) {
        $s['client'] = false; $s['client_at'] = $t;
        if ($s['session']) { $s['session'] = null; }
        $s['calling'] = null;
        $ev('link', 'LinBPQ detached'); return;
    }
    if (preg_match('/^command: MYCALL (.+)$/', $msg, $x)) { $s['mycall'] = trim($x[1]); return; }
    if (preg_match('/^command: BW(\d+)/', $msg, $x)) { $s['bw'] = (int)$x[1]; return; }
    if (preg_match('/^command: CONNECT (\S+) (\S+)/', $msg, $x)) {
        $s['calling'] = ['peer' => $x[2], 'at' => $t]; $ev('call', 'Calling ' . $x[2]); return;
    }
    if (strpos($msg, 'RX CONNECT_ACK') === 0 && $s['calling']) {
        $p = $s['calling']['peer'];
        $s['session'] = ['peer' => $p, 'dir' => 'out', 'since' => $t, 'bursts' => 0, 'mode' => null,
                         'snr_last' => null, 'snr_min' => null, 'snr_max' => null];
        $call('out', $p, 'connected'); $s['calling'] = null;
        $ev('connected', 'Connected to ' . $p); return;
    }
    if (preg_match('/^RX CONNECT (\S+)>(\S+)/', $msg, $x)) { $s['pending_in'] = ['peer' => $x[1], 'to' => $x[2], 'at' => $t]; return; }
    if (strpos($msg, 'TX CONNECT_ACK') === 0) {
        if ($s['pending_in'] && !$s['session']) {
            $p = $s['pending_in']['peer'];
            $s['session'] = ['peer' => $p, 'dir' => 'in', 'since' => $t, 'bursts' => 0, 'mode' => null,
                             'snr_last' => null, 'snr_min' => null, 'snr_max' => null];
            $call('in', $p, 'connected');
            $ev('connected', 'Connected from ' . $p);
        }
        $s['pending_in'] = null; return;
    }
    if (preg_match('/^refusing CONNECT from (\S+): (\S+)/', $msg, $x)) {
        $s['pending_in'] = null;
        $peer = $x[1]; $why = strtolower(str_replace('_', ' ', $x[2]));
        $call('in', $peer, 'refused');
        $n = count($s['events']); $last = $n ? $s['events'][$n - 1] : null;
        if ($last && $last['kind'] === 'missed' && ($last['peer'] ?? '') === $peer
            && strtotime($t . ' UTC') - strtotime($last['t'] . ' UTC') < 1800) {
            $k = ($last['count'] ?? 1) + 1;
            $s['events'][$n - 1] = ['t' => $t, 'kind' => 'missed', 'peer' => $peer, 'count' => $k,
                                    'text' => "$peer called, turned away: $why (×$k)"];
        } else {
            $s['events'][] = ['t' => $t, 'kind' => 'missed', 'peer' => $peer, 'count' => 1,
                              'text' => "$peer called, turned away: $why"];
            if (count($s['events']) > 30) $s['events'] = array_slice($s['events'], -30);
        }
        return;
    }
    if (preg_match('/^(session|stats) (\d+) s: tx (\d+) B acked \((\d+) bps[^)]*\), rx (\d+) B \((\d+) bps/', $msg, $x)) {
        $s['last_stats'] = ['kind' => $x[1], 'tx' => (int)$x[3], 'tx_bps' => (int)$x[4], 'rx' => (int)$x[5], 'rx_bps' => (int)$x[6]];
        return;
    }
    if (preg_match('/^DISCONNECTED ?(.*)$/', $msg, $x)) {
        $why = trim($x[1]);
        $first = strtok($why . ' ', ' ');
        if (!$s['session'] && $s['calling']) {
            $call('out', $s['calling']['peer'], 'failed');
            $ev('end', 'Call to ' . $s['calling']['peer'] . ' failed: ' . strtolower(str_replace('_', ' ', $why ?: 'ended')));
            $s['calling'] = null; $s['last_stats'] = null; return;
        }
        if ($s['session']) {
            $z = $s['session'] + ['ended_at' => $t, 'end' => $why];
            if ($s['last_stats'] && $s['last_stats']['kind'] === 'session') {
                $z['tx_bytes'] = $s['last_stats']['tx']; $z['rx_bytes'] = $s['last_stats']['rx'];
            }
            $s['sessions'][] = $z;
            if (count($s['sessions']) > 20) $s['sessions'] = array_slice($s['sessions'], -20);
            $words = ['CLIENT' => 'we hung up', 'PEER' => 'the other station hung up', 'LINK_FAILED' => 'link lost', 'LINK_LOST' => 'nothing heard from them',
                      'PEER_RECONNECTED' => 'the other station called again'];
            $ev('end', 'Disconnected from ' . $s['session']['peer'] . ' (' . ($words[$first] ?? strtolower(str_replace('_', ' ', $why))) . ')');
            $s['session'] = null;
        }
        $s['last_stats'] = null;
        return;
    }
    if (strpos($msg, 'command: ABORT') === 0) {
        if ($s['session']) {
            $z = $s['session'] + ['ended_at' => $t, 'end' => 'ABORT'];
            $s['sessions'][] = $z;
            if (count($s['sessions']) > 20) $s['sessions'] = array_slice($s['sessions'], -20);
            $ev('end', 'Ended by LinBPQ (abort): ' . $s['session']['peer']);
            $s['session'] = null;
        } elseif ($s['calling']) {
            $call('out', $s['calling']['peer'], 'failed');
            $ev('end', 'Call to ' . $s['calling']['peer'] . ' given up by LinBPQ');
            $s['calling'] = null;
        }
        return;
    }
    // sound card trouble: "audio input X: ended; feeding silence", "TX audio underrun (n)"
    if (preg_match('/^audio (input|output) .*?: (.*)$/', $msg, $x) && ($lvl === 'ERROR' || $lvl === 'WARNING')) {
        if ($x[1] === 'input' && $s['audio_ok']) { $s['audio_ok'] = false; $s['audio_changed_at'] = $t; $ev('audio', 'Sound card: ' . $x[2]); }
    }
    if ($lvl === 'ERROR' || $lvl === 'CRITICAL') {
        $s['errors'][] = ['t' => $t, 'text' => mb_substr($msg, 0, 200)];
        if (count($s['errors']) > 20) $s['errors'] = array_slice($s['errors'], -20);
    } elseif ($lvl === 'WARNING') {
        $s['warnings'][] = ['t' => $t, 'text' => mb_substr($msg, 0, 200)];
        if (count($s['warnings']) > 20) $s['warnings'] = array_slice($s['warnings'], -20);
    }
}

/** Read new log lines from $file starting at $offset; returns the new offset. */
function bpq_data2g_read(array &$s, string $file, int $offset, int $max = 8388608): int {
    $fh = @fopen($file, 'rb');
    if (!$fh) return $offset;
    fseek($fh, $offset);
    $buf = (string)fread($fh, $max);
    fclose($fh);
    $cut = strrpos($buf, "\n");
    if ($cut === false) return $offset;
    foreach (explode("\n", substr($buf, 0, $cut)) as $l) {
        if ($l !== '') bpq_data2g_line($s, rtrim($l, "\r"));
    }
    return $offset + $cut + 1;
}

/** Bring the cached state up to date with the log (copytruncate rotation: the same inode, smaller). */
function bpq_data2g_scan(string $log, string $cacheDir): array {
    $cache = rtrim($cacheDir, '/') . '/data2g-state.json';
    $lock = @fopen($cache . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $s = @is_readable($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
    if (!is_array($s) || ($s['log'] ?? '') !== $log) $s = bpq_data2g_fresh_state() + ['log' => $log];
    $s = $s + bpq_data2g_fresh_state();
    $s['log_readable'] = @is_readable($log);
    if ($s['log_readable']) {
        clearstatcache();
        $ino = (int)@fileinode($log); $size = (int)@filesize($log);
        if ($ino !== (int)$s['ino']) {
            $s['ino'] = $ino;
            // a new file is read from its start; the first look ever, its last 4 MB
            $s['offset'] = !empty($s['ino_seen']) ? 0 : max(0, $size - 4194304);
            $s['ino_seen'] = true;
            if ($s['offset'] > 0) {
                $fh = @fopen($log, 'rb');
                if ($fh) { fseek($fh, $s['offset']); fgets($fh); $s['offset'] = ftell($fh); fclose($fh); }
            }
        }
        if ($size < (int)$s['offset']) {                              // copytruncate: finish <log>.1 first
            $old = $log . '.1';
            if (@is_readable($old) && (int)@filesize($old) >= (int)$s['offset']) bpq_data2g_read($s, $old, (int)$s['offset']);
            $s['offset'] = 0;
        }
        if ($size > (int)$s['offset']) $s['offset'] = bpq_data2g_read($s, $log, (int)$s['offset']);
        $s['log_size'] = $size;
    }
    $tmp = $cache . '.tmp';
    if (@file_put_contents($tmp, json_encode($s)) !== false) @rename($tmp, $cache);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return $s;
}

/** Everything the RF Connections tile, Maintenance and System Audit need. */
function bpq_data2g_status(string $cacheDir): array {
    $cfg = bpq_data2g_settings();
    if ($cfg['enabled'] === false) return ['enabled' => false];
    $socks = bpq_mercury_sockets();
    $p = $cfg['tnc_port'];
    $listening = $socks === null ? null : (bool)array_filter($socks, fn($x) => $x['state'] === '0A' && $x['lport'] === $p);
    $attached  = $socks === null ? null : (bool)array_filter($socks, fn($x) => $x['state'] === '01' && $x['lport'] === $p);
    $proc = bpq_data2g_process();
    $logThere = @file_exists($cfg['log']);
    $bpqPort = null;
    foreach (bpq_linbpq_ports() as $num => $bp) {
        if (($bp['tnc_port'] ?? 0) === $p && in_array(strtolower($bp['tnc_host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)) {
            $bpqPort = ['num' => $num, 'name' => $bp['name']]; break;
        }
    }
    if (!$cfg['tnc_port_known']) $bpqPort = null;   // a VARA port on 8300 is not Data2G (v1.5.64)
    if ($cfg['enabled'] === 'auto' && !$logThere && !$cfg['unit_there'] && !$proc && !$bpqPort) return ['enabled' => false];

    $s = $logThere ? bpq_data2g_scan($cfg['log'], $cacheDir) : bpq_data2g_fresh_state() + ['log_readable' => false];
    $running = ($proc !== null && $proc !== '') || $listening === true;
    if ($socks === null && $proc === null)  $status = 'unknown';
    elseif (!$running)                      $status = 'down';
    elseif ($attached === false)            $status = 'nolink';
    elseif (!$s['audio_ok'])                $status = 'audio';
    elseif ($s['session'])                  $status = 'session';
    else                                    $status = 'up';
    $since = gmdate('Y-m-d H:i:s', time() - 86400);
    $err24  = array_values(array_filter($s['errors'], fn($e) => $e['t'] >= $since));
    $warn24 = array_values(array_filter($s['warnings'] ?? [], fn($e) => $e['t'] >= $since));
    $c24 = array_values(array_filter($s['calls'] ?? [], fn($c) => $c['t'] >= $since));
    $calls = ['in' => 0, 'in_connected' => 0, 'in_refused' => 0, 'out' => 0, 'out_connected' => 0];
    foreach ($c24 as $c) {
        $calls[$c['dir']]++;
        if ($c['outcome'] === 'connected') $calls[$c['dir'] . '_connected']++;
        if ($c['outcome'] === 'refused') $calls['in_refused']++;
    }
    $ver = @is_readable($cfg['version_file']) ? trim((string)@file_get_contents($cfg['version_file'])) : '';
    $web = null;
    if ($cfg['web_port'] > 0) {
        $webUp = $socks === null ? null : (bool)array_filter($socks, fn($x) => $x['state'] === '0A' && $x['lport'] === $cfg['web_port']);
        $web = ['port' => $cfg['web_port'], 'listen' => $cfg['web_listen'], 'up' => $webUp,
                'local_only' => in_array($cfg['web_listen'], ['127.0.0.1', 'localhost', '::1'], true)];
    }
    return [
        'enabled' => true, 'status' => $status, 'running' => $running, 'process' => $proc,
        'listening' => $listening, 'attached' => $attached, 'tnc_port' => $p, 'kiss_port' => $cfg['kiss_port'],
        'bpq_port' => $bpqPort, 'monitor' => $web, 'unit_there' => $cfg['unit_there'], 'tz' => $cfg['tz'],
        'log_readable' => (bool)$s['log_readable'], 'log_there' => $logThere,
        'version' => $ver !== '' ? mb_substr($ver, 0, 32) : null, 'mycall' => $s['mycall'], 'bw' => $s['bw'],
        'started_at' => $s['started_at'],
        'last_listen_at' => $s['last_listen_at'], 'listen' => $s['listen'], 'last_tx_at' => $s['last_tx_at'],
        'last_rx' => $s['last_rx'], 'audio_ok' => $s['audio_ok'], 'audio_changed_at' => $s['audio_changed_at'],
        'calling' => $s['calling'], 'session' => $s['session'],
        'sessions' => array_slice(array_reverse($s['sessions']), 0, 5),
        'events' => array_slice(array_reverse($s['events']), 0, 12),
        'calls_24h' => $calls,
        'errors_24h' => count($err24), 'last_error' => $err24 ? end($err24) : null,
        'warnings_24h' => count($warn24), 'last_warning' => $warn24 ? end($warn24) : null,
        'checked_at' => gmdate('Y-m-d H:i:s'),
    ];
}
