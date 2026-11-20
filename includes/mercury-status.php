<?php
/**
 * includes/mercury-status.php — Mercury HF modem status (BPQ Dashboard v1.5.43)
 *
 * Mercury (Rhizomatica, open-source HF modem) offers a VARA-style TNC that
 * LinBPQ attaches to as a VARA port. It takes one client at a time on its
 * control port and LinBPQ holds it, so the dashboard never talks to Mercury:
 * the status comes from what can be seen from outside —
 *   - the process (/proc/<pid>/comm "mercury") and its TNC sockets
 *     (/proc/net/tcp: listening, and LinBPQ attached),
 *   - Mercury's log file (-L), read incrementally like the BBS-lines cache:
 *     cache/mercury-state.json keeps the file's inode and offset plus what
 *     was found (version, callsign, last LISTEN, last transmit, last decoded
 *     frame and its S/N, audio failures, sessions, errors).
 *
 * Settings, config.php (all optional):
 *   'mercury' => [
 *       'enabled'  => 'auto',   // auto: show when Mercury's log or TNC is there; true; false
 *       'log'      => '/var/log/mercury/mercury.log',
 *       'ini'      => '/etc/mercury/mercury.ini',  // read for the TNC port
 *       'tnc_port' => 8400,     // default: arq_tcp_base_port from the ini, else 8400
 *   ],
 * /includes/ is 403 on the web; PHP require only.
 */

require_once __DIR__ . '/station-config.php';

function bpq_mercury_settings(): array {
    $c   = bpq_config_section('mercury');
    $ini = (string)($c['ini'] ?? '/etc/mercury/mercury.ini');
    $port = isset($c['tnc_port']) ? (int)$c['tnc_port'] : 0;
    $iniPort = 0; $bcast = 0; $uiOn = false; $uiPort = 10000;
    if (@is_readable($ini)) {
        foreach ((array)@file($ini, FILE_IGNORE_NEW_LINES) as $l) {
            if (preg_match('/^\s*arq_tcp_base_port\s*=\s*(\d+)/', $l, $m)) $iniPort = (int)$m[1];
            if (preg_match('/^\s*broadcast_tcp_port\s*=\s*(\d+)/', $l, $m)) $bcast = (int)$m[1];
            // v1.5.49: Mercury's own monitor (WebSocket + web pages)
            if (preg_match('/^\s*ui_enabled\s*=\s*(\S+)/', $l, $m)) $uiOn = filter_var($m[1], FILTER_VALIDATE_BOOLEAN);
            if (preg_match('/^\s*ui_port\s*=\s*(\d+)/', $l, $m)) $uiPort = (int)$m[1];
        }
    }
    if ($port <= 0) $port = $iniPort;
    $en = $c['enabled'] ?? 'auto';
    if (is_string($en)) $en = strtolower($en) === 'auto' ? 'auto' : filter_var($en, FILTER_VALIDATE_BOOLEAN);
    return [
        'enabled'  => $en,
        'log'      => (string)($c['log'] ?? '/var/log/mercury/mercury.log'),
        'ini'      => $ini,
        'tnc_port' => $port > 0 ? $port : 8400,
        // v1.5.80: 8400 is only Mercury's default guess, and Data2G's web
        // monitor (data2g-web) also listens on 8400 by default. Something on
        // that port only means Mercury when the port came from config.php or
        // mercury.ini, or a mercury process is running.
        'tnc_port_known' => $port > 0,
        'broadcast_port' => $bcast > 0 ? $bcast : 8100,     // Mercury's default; clashes with QtSoundModem's KISS port
        'ui_enabled' => $uiOn,                              // mercury.ini [main] ui_enabled (v1.5.49)
        'ui_port'    => $uiPort > 0 ? $uiPort : 10000,
    ];
}

/** Sockets from /proc/net/tcp{,6}: lport, rport, state (0A listen, 01 established). */
function bpq_mercury_sockets(): ?array {
    $out = []; $any = false;
    foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $f) {
        $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) continue;
        $any = true;
        array_shift($lines);
        foreach ($lines as $l) {
            $c = preg_split('/\s+/', trim($l));
            if (count($c) < 4) continue;
            $out[] = ['lport' => hexdec(substr($c[1], strrpos($c[1], ':') + 1)),
                      'rport' => hexdec(substr($c[2], strrpos($c[2], ':') + 1)),
                      'state' => $c[3]];
        }
    }
    return $any ? $out : null;
}

/** Is a process named "mercury" running? null when /proc can't be read. */
function bpq_mercury_process(): ?bool {
    $dirs = @glob('/proc/[0-9]*', GLOB_ONLYDIR);
    if (!$dirs) return null;
    foreach ($dirs as $d) {
        if (trim((string)@file_get_contents("$d/comm")) === 'mercury') return true;
    }
    return false;
}

/** "2026-11-13 22:57:47.613Z" (UTC) or without Z (server local time) -> "Y-m-d H:i:s" UTC. */
function bpq_mercury_ts(string $d, string $z): string {
    if ($z === 'Z') return $d;
    $t = strtotime($d);
    return $t ? gmdate('Y-m-d H:i:s', $t) : $d;
}

function bpq_mercury_fresh_state(): array {
    return ['ino' => 0, 'offset' => 0, 'version' => null, 'mycall' => null, 'started_at' => null,
            'stopped_at' => null, 'last_listen_at' => null, 'listen' => null, 'last_tx_at' => null,
            'last_rx' => null, 'audio_ok' => true, 'audio_changed_at' => null, 'calling' => null,
            'pending_in' => null, 'session' => null, 'sessions' => [], 'events' => [], 'errors' => [],
            'calls' => [], 'drops' => [], 'disc' => null];
}

/** Feed one log line into the state. */
function bpq_mercury_line(array &$s, string $line): void {
    // 2026-11-13 22:57:47.613Z [+118.998s] [INF] [arq] My callsign: K1AJD-4
    if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?:\.\d+)?(Z?)\s+(?:\[\+[\d.]+s\]\s+)?\[(\w{3})\]\s+\[([^\]]*)\]\s?(.*)$/', $line, $m)) {
        if (preg_match('/Mercury(?: Version)? (\d+\.\d+\.\d+)/', $line, $v)) $s['version'] = $v[1];
        return;
    }
    [, $d, $z, $lvl, $comp, $msg] = $m;
    // the LISTEN toggles of a scanning LinBPQ are most of the log: handle them first
    if (strpos($msg, 'Command received: LISTEN ') === 0) {
        $s['last_listen_at'] = bpq_mercury_ts($d, $z);
        $s['listen'] = trim(substr($msg, 25)) === 'ON';
        return;
    }
    // "LISTEN OFF 0.0 s into a MFSK500 frame not yet decoded: LISTEN OFF drops it":
    // a scan hop cut a frame (Mercury docs, Scanning hosts). Counted per hour.
    if (strpos($msg, 'LISTEN OFF ') === 0 && strpos($msg, 'drops it') !== false) {
        $h = substr(bpq_mercury_ts($d, $z), 0, 13);
        $s['drops'][$h] = ($s['drops'][$h] ?? 0) + 1;
        if (count($s['drops']) > 48) $s['drops'] = array_slice($s['drops'], -48, null, true);
        return;
    }
    $t = bpq_mercury_ts($d, $z);
    // one row per incoming / outgoing call attempt and how it went (v1.5.43, from real logs)
    $call = function (string $dir, string $peer, string $outcome) use (&$s, $t) {
        $s['calls'][] = ['t' => $t, 'dir' => $dir, 'peer' => $peer, 'outcome' => $outcome];
        if (count($s['calls']) > 300) $s['calls'] = array_slice($s['calls'], -300);
    };
    // drop the "Incoming call from X" line once the call has an outcome (connected / not completed)
    $dropIn = function (string $peer) use (&$s) {
        for ($i = count($s['events']) - 1, $k = 0; $i >= 0 && $k < 3; $i--, $k++) {
            if ($s['events'][$i]['kind'] === 'in' && $s['events'][$i]['text'] === 'Incoming call from ' . $peer) {
                array_splice($s['events'], $i, 1); return;
            }
        }
    };
    $ev = function (string $kind, string $text) use (&$s, $t) {
        $s['events'][] = ['t' => $t, 'kind' => $kind, 'text' => mb_substr($text, 0, 160)];
        if (count($s['events']) > 30) $s['events'] = array_slice($s['events'], -30);
    };
    $end = function (string $why) use (&$s, $t, $ev) {
        if ($s['session']) {
            $x = $s['session'] + ['ended_at' => $t, 'end' => $why];
            if ($s['disc']) { $x = array_merge($x, $s['disc']); $s['disc'] = null; }
            $s['sessions'][] = $x;
            if (count($s['sessions']) > 20) $s['sessions'] = array_slice($s['sessions'], -20);
            $s['session'] = null;
        }
        $ev('end', $why);
    };

    if (preg_match('/^Mercury (\d+\.\d+\.\d+)/', $msg, $v)) { $s['version'] = $v[1]; return; }
    if ($msg === 'Mercury engine initialised') {
        $s['started_at'] = $t; $s['stopped_at'] = null; $s['session'] = null;
        $s['calling'] = null; $s['pending_in'] = null; $s['audio_ok'] = true;
        $ev('start', 'Mercury started'); return;
    }
    if ($comp === 'engine' && $msg === 'Shutting down') { $s['stopped_at'] = $t; $ev('stop', 'Mercury stopped'); return; }
    if (preg_match('/^My callsign: (\S+)/', $msg, $c)) { $s['mycall'] = $c[1]; return; }
    if (preg_match('/^Command received: CONNECT (\S+) (\S+)/', $msg, $c)) {
        $s['calling'] = ['peer' => $c[2], 'at' => $t];
        $ev('call', 'Calling ' . $c[2]); return;
    }
    if ($msg === 'Control client connected')        { $ev('link', 'LinBPQ attached'); return; }
    if ($msg === 'Control client closed the connection') { $ev('link', 'LinBPQ detached'); return; }
    if (preg_match('/^Incoming connection from (\S+) on (\S+)/', $msg, $c)) {
        $s['pending_in'] = ['peer' => $c[1], 'at' => $t];
        $ev('in', 'Incoming call from ' . $c[1]); return;
    }
    if ($msg === 'Incoming connection cancelled') {
        // A scanning LinBPQ hops away (LISTEN OFF) six scan intervals after PENDING,
        // which cancels a connect still being set up. Shown as one line per caller run.
        $peer = $s['pending_in']['peer'] ?? '?';
        $s['pending_in'] = null;
        $call('in', $peer, 'cancelled');
        $dropIn($peer);
        $n = count($s['events']);
        $lastMiss = $n ? $s['events'][$n - 1] : null;
        if ($lastMiss && $lastMiss['kind'] === 'missed' && ($lastMiss['peer'] ?? '') === $peer
            && strtotime($t . ' UTC') - strtotime($lastMiss['t'] . ' UTC') < 1800) {
            $k = ($lastMiss['count'] ?? 1) + 1;
            $s['events'][$n - 1] = ['t' => $t, 'kind' => 'missed', 'peer' => $peer, 'count' => $k,
                                    'text' => "$peer called, not completed (×$k)"];
        } else {
            $s['events'][] = ['t' => $t, 'kind' => 'missed', 'peer' => $peer, 'count' => 1, 'text' => "$peer called, not completed"];
            if (count($s['events']) > 30) $s['events'] = array_slice($s['events'], -30);
        }
        return;
    }
    // [TMG] [arq-timing] connect mode=DATAC16 / disconnect reason=timeout tx_bytes=44 rx_bytes=0 frames_tx=2 frames_rx=2
    if ($comp === 'arq-timing') {
        if ($s['session'] && preg_match('/^connect mode=(\S+)/', $msg, $c)) $s['session']['mode'] = $c[1];
        if (preg_match('/^disconnect reason=(\S+) tx_bytes=(\d+) rx_bytes=(\d+) frames_tx=(\d+) frames_rx=(\d+)/', $msg, $c)) {
            $s['disc'] = ['reason' => $c[1], 'tx_bytes' => (int)$c[2], 'rx_bytes_total' => (int)$c[3],
                          'frames_tx' => (int)$c[4], 'frames_rx' => (int)$c[5]];
        }
        return;
    }
    if ($lvl === 'WRN' && strpos($msg, 'link lost') !== false && $s['session']) {
        $s['session']['lost'] = preg_replace('/^carousel: /', '', $msg); return;
    }
    if (preg_match('/^Connected to (\S+)/', $msg, $c)) {
        $dir = ($s['pending_in'] && strcasecmp($s['pending_in']['peer'], $c[1]) === 0) ? 'in' : 'out';
        $s['session'] = ['peer' => $c[1], 'dir' => $dir, 'since' => $t, 'frames' => 0, 'rx_bytes' => 0,
                         'snr_last' => null, 'snr_min' => null, 'snr_max' => null];
        $call($dir, $c[1], 'connected');
        if ($dir === 'in') $dropIn($c[1]);
        $s['calling'] = null; $s['pending_in'] = null;
        $ev('connected', ($dir === 'in' ? 'Connected from ' : 'Connected to ') . $c[1]); return;
    }
    if (preg_match('/^Disconnect finalized \((.*)\)$/', $msg, $c)) {
        if (!$s['session'] && $s['calling']) { $call('out', $s['calling']['peer'], 'failed'); $s['calling'] = null; $ev('end', 'Call failed: ' . $c[1]); return; }
        $end('Disconnected: ' . $c[1]); return;
    }
    if ($msg === 'Disconnected' || strpos($msg, 'Disconnected (') === 0 || strpos($msg, 'Session ended') === 0
        || strpos($msg, "The peer's application has hung up") === 0) {
        if ($s['session'] || $s['calling']) {
            if (!$s['session']) { $call('out', $s['calling']['peer'], 'failed'); $s['calling'] = null; $ev('end', 'Call ended: ' . $msg); return; }
            $why = $s['session']['lost'] ?? '';
            if (!$why && $s['disc'] && $s['disc']['reason'] !== '') $why = 'reason: ' . $s['disc']['reason'];
            $end('Disconnected from ' . $s['session']['peer'] . ($why ? " ($why)" : ''));
        }
        return;
    }
    if (preg_match('/DECODED FRAME \d+: (\d+) bytes, SNR: (-?[\d.]+) dB/', $msg, $c)) {
        $snr = round((float)$c[2], 1);
        $s['last_rx'] = ['at' => $t, 'snr' => $snr, 'bytes' => (int)$c[1]];
        if ($s['session']) {
            $x = &$s['session'];
            $x['frames']++; $x['rx_bytes'] += (int)$c[1]; $x['snr_last'] = $snr;
            $x['snr_min'] = $x['snr_min'] === null ? $snr : min($x['snr_min'], $snr);
            $x['snr_max'] = $x['snr_max'] === null ? $snr : max($x['snr_max'], $snr);
            unset($x);
        }
        return;
    }
    if (strpos($msg, 'TX enabled (PTT ON)') !== false) { $s['last_tx_at'] = $t; return; }
    if (strpos($msg, 'the capture device delivers no audio') !== false) {   // repeated while it lasts
        if ($s['audio_ok']) { $s['audio_ok'] = false; $s['audio_changed_at'] = $t; $ev('audio', 'Sound card delivers no audio'); }
        return;
    }
    if (strpos($msg, 'capture delivering again') !== false) {
        $s['audio_ok'] = true; $s['audio_changed_at'] = $t; $ev('audio', 'Sound card audio back'); return;
    }
    // at every stop with a shared (dsnoop) capture device: harmless
    if ($lvl === 'ERR' && strpos($msg, 'snd_pcm_pause') === false) {
        $s['errors'][] = ['t' => $t, 'text' => mb_substr("[$comp] $msg", 0, 200)];
        if (count($s['errors']) > 20) $s['errors'] = array_slice($s['errors'], -20);
    }
}

/** Read new log lines from $file starting at $offset; returns the new offset. */
function bpq_mercury_read(array &$s, string $file, int $offset, int $max = 8388608): int {
    $fh = @fopen($file, 'rb');
    if (!$fh) return $offset;
    fseek($fh, $offset);
    $buf = (string)fread($fh, $max);
    fclose($fh);
    $cut = strrpos($buf, "\n");
    if ($cut === false) return $offset;                    // no complete line yet
    foreach (explode("\n", substr($buf, 0, $cut)) as $l) {
        if ($l !== '') bpq_mercury_line($s, rtrim($l, "\r"));
    }
    return $offset + $cut + 1;
}

/** Bring the cached state up to date with the log. */
function bpq_mercury_scan(string $log, string $cacheDir): array {
    $cache = rtrim($cacheDir, '/') . '/mercury-state.json';
    $lock = @fopen($cache . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $s = @is_readable($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
    if (!is_array($s) || ($s['log'] ?? '') !== $log) $s = bpq_mercury_fresh_state() + ['log' => $log];
    $s = $s + bpq_mercury_fresh_state();
    $s['log_readable'] = @is_readable($log);
    if ($s['log_readable']) {
        clearstatcache();
        $ino = (int)@fileinode($log); $size = (int)@filesize($log);
        if ($ino !== (int)$s['ino']) {
            // Mercury moves a full log to <log>.1: finish the old file first
            $old = $log . '.1';
            if ($s['ino'] && @is_readable($old) && (int)@fileinode($old) === (int)$s['ino']) {
                bpq_mercury_read($s, $old, (int)$s['offset']);
            }
            $s['ino'] = $ino;
            // a new file after rotation is read from its start; the first look ever, its last 4 MB
            $s['offset'] = !empty($s['ino_seen']) ? 0 : max(0, $size - 4194304);
            $s['ino_seen'] = true;
            if ($s['offset'] > 0) {                                   // start on a whole line
                $fh = @fopen($log, 'rb');
                if ($fh) { fseek($fh, $s['offset']); fgets($fh); $s['offset'] = ftell($fh); fclose($fh); }
            }
        }
        if ($size < (int)$s['offset']) $s['offset'] = 0;               // truncated
        if ($size > (int)$s['offset']) $s['offset'] = bpq_mercury_read($s, $log, (int)$s['offset']);
        $s['log_size'] = $size;
    }
    $tmp = $cache . '.tmp';
    if (@file_put_contents($tmp, json_encode($s)) !== false) @rename($tmp, $cache);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return $s;
}

/** Everything the RF Connections tile, Maintenance and System Audit need. */
function bpq_mercury_status(string $cacheDir): array {
    $cfg = bpq_mercury_settings();
    if ($cfg['enabled'] === false) return ['enabled' => false];
    $socks = bpq_mercury_sockets();
    $p = $cfg['tnc_port'];
    $listening = $socks === null ? null : (bool)array_filter($socks, fn($x) => $x['state'] === '0A' && $x['lport'] === $p);
    $attached  = $socks === null ? null : (bool)array_filter($socks, fn($x) => $x['state'] === '01' && $x['lport'] === $p);
    $proc = bpq_mercury_process();
    $logThere = @file_exists($cfg['log']);
    $bpqPort = null;
    foreach (bpq_linbpq_ports() as $num => $bp) {
        if (($bp['tnc_port'] ?? 0) === $p && in_array(strtolower($bp['tnc_host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)) {
            $bpqPort = ['num' => $num, 'name' => $bp['name']]; break;
        }
    }
    if (!$cfg['tnc_port_known'] && $proc !== true) {
        // the 8400 guess, no mercury process: whatever listens there isn't
        // known to be Mercury (Data2G's web monitor, another program)
        $listening = $attached = $socks === null ? null : false;
        $bpqPort = null;
    }
    if ($cfg['enabled'] === 'auto' && !$logThere && !$listening && !$proc && !$bpqPort) return ['enabled' => false];

    $s = $logThere ? bpq_mercury_scan($cfg['log'], $cacheDir) : bpq_mercury_fresh_state() + ['log_readable' => false];
    $running = $proc === true || $listening === true;
    if ($socks === null && $proc === null)  $status = 'unknown';
    elseif (!$running)                      $status = 'down';
    elseif ($attached === false)            $status = 'nolink';
    elseif (!$s['audio_ok'])                $status = 'audio';
    elseif ($s['session'])                  $status = 'session';
    else                                    $status = 'up';
    $since = gmdate('Y-m-d H:i:s', time() - 86400);
    $err24 = array_values(array_filter($s['errors'], fn($e) => $e['t'] >= $since));
    $c24 = array_values(array_filter($s['calls'] ?? [], fn($c) => $c['t'] >= $since));
    $calls = ['in' => 0, 'in_connected' => 0, 'out' => 0, 'out_connected' => 0];
    foreach ($c24 as $c) { $calls[$c['dir']]++; if ($c['outcome'] === 'connected') $calls[$c['dir'] . '_connected']++; }
    $drops = 0; $sinceH = substr($since, 0, 13);
    foreach (($s['drops'] ?? []) as $h => $n) if ($h >= $sinceH) $drops += $n;
    return [
        'enabled' => true, 'status' => $status, 'running' => $running, 'process' => $proc,
        'listening' => $listening, 'attached' => $attached, 'tnc_port' => $p, 'bpq_port' => $bpqPort,
        'log_readable' => (bool)$s['log_readable'], 'log_there' => $logThere,
        'version' => $s['version'], 'mycall' => $s['mycall'], 'started_at' => $s['started_at'],
        'last_listen_at' => $s['last_listen_at'], 'listen' => $s['listen'], 'last_tx_at' => $s['last_tx_at'],
        'last_rx' => $s['last_rx'], 'audio_ok' => $s['audio_ok'], 'audio_changed_at' => $s['audio_changed_at'],
        'calling' => $s['calling'], 'pending_in' => $s['pending_in'], 'session' => $s['session'],
        'sessions' => array_slice(array_reverse($s['sessions']), 0, 5),
        'events' => array_slice(array_reverse($s['events']), 0, 12),
        'calls_24h' => $calls, 'scan_drops_24h' => $drops,
        'errors_24h' => count($err24), 'last_error' => $err24 ? end($err24) : null,
        'checked_at' => gmdate('Y-m-d H:i:s'),
    ];
}
