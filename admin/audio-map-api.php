<?php
/**
 * audio-map-api.php — Admin > Audio Map (BPQ Dashboard v1.5.66)
 *
 * How the station's sound modems reach its radios, worked out from the live
 * system, as JSON for admin/audio-map.html:
 *   LinBPQ ports (bpq32.cfg: VARA-type ADDR / KISS IPADDR+TCPPORT, INTERLOCK,
 *   PTT, RADIO blocks)  ->  the modem programs listening on those ports  ->
 *   the ALSA devices they go through (/etc/asound.conf: plug / asym / dmix /
 *   dsnoop ...)  ->  the sound cards (/proc/asound)  ->  the radios.
 *
 * Who has which sound card open, through which dmix/dsnoop, and the cards'
 * mixer levels need root: they come from /usr/local/sbin/bpq-dashboard-audio-map
 * (tools/audio-map-helper), run with `sudo -n` and no arguments
 * (www-data-tools.sudoers). Without it the page still shows the cards, the
 * ALSA devices and LinBPQ's ports, and says what's missing.
 *
 * From bpq32.cfg only the port fields named above and the RADIO lines'
 * control method (flrig / Hamlib host:port, or "serial") are read out; nothing
 * else of the file leaves the server.
 *
 * Optional config.php:
 *   'audio_map' => ['cards' => ['CODEC' => 'FTdx-3000 (SCU-17)', 'Device' => '2 m radio']],
 *     names for the radios behind each card (by card id, as /proc/asound/cards
 *     shows it in [brackets], or by number).
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/web-login.php';
bpq_require_web_login('Audio Map');
require_once dirname(__DIR__) . '/includes/mercury-status.php';
require_once dirname(__DIR__) . '/includes/data2g-status.php';

const HELPER = '/usr/local/sbin/bpq-dashboard-audio-map';
// Tests only: from the command line, AUDIO_MAP_TEST_ROOT points every system
// path at a fake tree (and helper.json in it stands in for the helper).
$TEST_ROOT = (PHP_SAPI === 'cli' && getenv('AUDIO_MAP_TEST_ROOT')) ? rtrim((string)getenv('AUDIO_MAP_TEST_ROOT'), '/') : '';
function am_path(string $p): string { global $TEST_ROOT; return $TEST_ROOT . $p; }
$notes = [];
$note = function (string $lvl, string $text) use (&$notes) { $notes[] = ['lvl' => $lvl, 'text' => $text]; };

// ── 1. Sound cards ──────────────────────────────────────────────────────────
$cards = [];
$lines = @file(am_path('/proc/asound/cards'), FILE_IGNORE_NEW_LINES) ?: [];
for ($i = 0; $i < count($lines); $i++) {
    if (!preg_match('/^\s*(\d+)\s+\[(\S+)\s*\]:\s*(\S+)\s+-\s+(.*)$/', $lines[$i], $m)) continue;
    $n = (int)$m[1];
    $cards[$n] = ['index' => $n, 'id' => $m[2], 'driver' => $m[3], 'name' => trim($m[4]),
                  'long' => trim($lines[$i + 1] ?? ''), 'usbid' => trim((string)@file_get_contents(am_path("/proc/asound/card$n/usbid"))),
                  'devices' => []];
    foreach (glob(am_path("/proc/asound/card$n/pcm*[pc]")) ?: [] as $d) {
        if (preg_match('/pcm(\d+)([pc])$/', $d, $x)) $cards[$n]['devices'][] = 'pcmC' . $n . 'D' . $x[1] . $x[2];
    }
}
$labels = (array)(bpq_config_section('audio_map')['cards'] ?? []);
foreach ($cards as $n => &$c) {
    $l = $labels[$c['id']] ?? $labels[(string)$n] ?? $labels[$n] ?? null;
    $c['label'] = is_string($l) && trim($l) !== '' ? mb_substr(trim($l), 0, 60) : null;
}
unset($c);
$cardByName = function (string $s) use ($cards): ?int {     // "CODEC", "0"
    if (ctype_digit($s)) return isset($cards[(int)$s]) ? (int)$s : null;
    foreach ($cards as $n => $c) if (strcasecmp($c['id'], $s) === 0) return $n;
    return null;
};
if (!$cards) $note('warn', 'No sound cards found in /proc/asound/cards.');

// ── 2. Who has the cards open (root helper) ────────────────────────────────
$helper = ['ok' => false, 'error' => null, 'generated_at' => null];
$h = null;
if ($TEST_ROOT !== '') {
    $h = json_decode((string)@file_get_contents($TEST_ROOT . '/helper.json'), true);
    $helper = ['ok' => is_array($h), 'error' => is_array($h) ? null : 'not_installed', 'generated_at' => $h['generated_at'] ?? null];
    if (!is_array($h)) $h = null;
} elseif (!@file_exists(HELPER)) {
    $helper['error'] = 'not_installed';
} else {
    $out = (string)shell_exec('sudo -n ' . HELPER . ' 2>&1');
    $h = json_decode($out, true);
    if (!is_array($h)) {
        $helper['error'] = preg_match('/password is required|not allowed|may not run sudo|^sudo:/m', $out) ? 'sudo_refused' : 'failed';
        $helper['detail'] = mb_substr(trim($out), 0, 200);
        $h = null;
    } else {
        $helper['ok'] = true;
        $helper['generated_at'] = $h['generated_at'] ?? null;
    }
}

// ── 3. /etc/asound.conf (+ users' ~/.asoundrc): the ALSA devices built on the cards ───────────────
function alsa_tokens(string $t): array {
    $t = preg_replace('/(^|\s)#[^\n]*/', '$1', $t);
    preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[{}\[\]]|[^\s{}\[\];,=#"\']+/', $t, $m);
    return $m[0];
}
function alsa_block(array $tk, int &$i): array {
    $out = [];
    while ($i < count($tk)) {
        $k = $tk[$i++];
        if ($k === '}' || $k === ']') return $out;
        if ($i >= count($tk)) break;
        $v = $tk[$i];
        if ($v === '{') { $i++; $val = alsa_block($tk, $i); }
        elseif ($v === '[') { $i++; $val = alsa_block($tk, $i); }
        else { $i++; $val = trim($v, '"\''); }
        $ref = &$out;
        $parts = explode('.', trim($k, '"\''));
        foreach ($parts as $j => $p) {
            $p = ltrim($p, '!?');
            if ($j === count($parts) - 1) {
                $ref[$p] = (isset($ref[$p]) && is_array($ref[$p]) && is_array($val)) ? array_replace_recursive($ref[$p], $val) : $val;
            } else {
                if (!isset($ref[$p]) || !is_array($ref[$p])) $ref[$p] = [];
                $ref = &$ref[$p];
            }
        }
        unset($ref);
    }
    return $out;
}
$conf = am_path('/etc/asound.conf');
$alsa = [];
if (@is_readable($conf)) {
    $tk = alsa_tokens((string)@file_get_contents($conf));
    $i = 0;
    $alsa = alsa_block($tk, $i);
}
$pcmDefs = is_array($alsa['pcm'] ?? null) ? $alsa['pcm'] : [];
$pcmFrom = array_fill_keys(array_keys($pcmDefs), '/etc/asound.conf');
// each sound user's own ~/.asoundrc (from the helper): their devices count too
foreach ((array)($h['asoundrc'] ?? []) as $u => $txt) {
    $tk = alsa_tokens((string)$txt); $i = 0;
    $a = alsa_block($tk, $i);
    foreach ((array)($a['pcm'] ?? []) as $n => $def) {
        if (!is_array($def)) continue;
        $pcmDefs[$n] = $def;
        $pcmFrom[$n] = "~$u/.asoundrc";
    }
}
$pcms = [];                    // name => {type, ipc_key, desc, hw:{card,dev}|null, slaves:[{to, role}]}
$anon = 0;
function slave_ref($v): array {   // a slave: "name" | "hw:CODEC,0" | {pcm "..."} | {type ...}
    if (is_string($v)) return ['ref' => $v];
    if (is_array($v) && isset($v['pcm'])) return slave_ref($v['pcm']);
    if (is_array($v) && isset($v['type'])) return ['def' => $v];
    return [];
}
$addPcm = function (string $name, array $def, bool $named) use (&$addPcm, &$pcms, &$anon, $cardByName) {
    $type = is_string($def['type'] ?? null) ? $def['type'] : '?';
    $p = ['name' => $name, 'named' => $named, 'type' => $type, 'ipc_key' => isset($def['ipc_key']) ? (int)$def['ipc_key'] : null,
          'desc' => is_array($def['hint'] ?? null) && is_string($def['hint']['description'] ?? null) ? mb_substr($def['hint']['description'], 0, 80) : null,
          'hw' => null, 'slaves' => []];
    if ($type === 'hw' && isset($def['card'])) {
        $c = $cardByName((string)$def['card']);
        $p['hw'] = ['card' => $c, 'dev' => (int)($def['device'] ?? 0), 'raw' => (string)$def['card']];
    }
    $links = [];
    if ($type === 'asym') {
        if (isset($def['playback'])) $links[] = ['role' => 'playback', 's' => slave_ref($def['playback']['pcm'] ?? $def['playback'])];
        if (isset($def['capture']))  $links[] = ['role' => 'capture',  's' => slave_ref($def['capture']['pcm'] ?? $def['capture'])];
    } elseif (isset($def['slave'])) {
        $links[] = ['role' => $type === 'dmix' ? 'playback' : ($type === 'dsnoop' ? 'capture' : ''), 's' => slave_ref($def['slave'])];
    }
    foreach ($links as $l) {
        $s = $l['s'];
        if (isset($s['def'])) {
            $sub = $name . '·' . (++$anon);
            $addPcm($sub, $s['def'], false);
            $p['slaves'][] = ['to' => $sub, 'role' => $l['role']];
        } elseif (isset($s['ref'])) {
            $r = $s['ref'];
            if (preg_match('/^(?:plug)?hw:(?:CARD=)?([^,\s]+)(?:,(?:DEV=)?(\d+))?/i', $r, $x)) {
                $hwName = 'hw:' . $x[1] . ',' . ($x[2] ?? '0');
                if (!isset($pcms[$hwName])) {
                    $c = $cardByName($x[1]);
                    $pcms[$hwName] = ['name' => $hwName, 'named' => false, 'type' => 'hw', 'ipc_key' => null, 'desc' => null,
                                      'hw' => ['card' => $c, 'dev' => (int)($x[2] ?? 0), 'raw' => $x[1]], 'slaves' => []];
                }
                $p['slaves'][] = ['to' => $hwName, 'role' => $l['role']];
            } else {
                $p['slaves'][] = ['to' => $r, 'role' => $l['role']];
            }
        }
    }
    $pcms[$name] = $p;
};
foreach ($pcmDefs as $name => $def) if (is_array($def)) $addPcm((string)$name, $def, true);
foreach ($pcms as $n => &$p) $p['from'] = $pcmFrom[$n] ?? null;
unset($p);
// which card(s) each device ends on, and the dmix/dsnoop under it
$reach = function (string $n, array $seen = []) use (&$reach, $pcms): array {
    if (isset($seen[$n]) || !isset($pcms[$n])) return ['cards' => [], 'share' => []];
    $seen[$n] = 1;
    $p = $pcms[$n];
    $r = ['cards' => [], 'share' => []];
    if ($p['hw'] && $p['hw']['card'] !== null) $r['cards'][$p['hw']['card']] = 1;
    if (in_array($p['type'], ['dmix', 'dsnoop'], true)) $r['share'][$n] = 1;
    foreach ($p['slaves'] as $s) {
        $x = $reach($s['to'], $seen);
        $r['cards'] += $x['cards']; $r['share'] += $x['share'];
    }
    return $r;
};
foreach ($pcms as $n => &$p) {
    $x = $reach($n);
    $p['cards'] = array_keys($x['cards']);
    $p['shares'] = array_keys($x['share']);
}
unset($p);

// ── 4. LinBPQ ports and radios (bpq32.cfg; only the fields named above) ─────
$bpq = ['ports' => [], 'radios' => [], 'read' => false];
$dir = (string)(bpq_config_section('paths')['linbpq'] ?? '');
$cfgFile = am_path(rtrim($dir, '/') . '/bpq32.cfg');
if ($dir !== '' && @is_readable($cfgFile)) {
    $bpq['read'] = true;
    $text = (string)@file_get_contents($cfgFile);
    $text = preg_replace('#/\*.*?\*/#s', '', $text);
    $text = implode("\n", array_map(fn($l) => explode(';', $l, 2)[0], preg_split('/\r\n|\r|\n/', $text)));
    if (preg_match_all('/^\s*PORT\b(.*?)^\s*ENDPORT\b/smi', $text, $mm)) {
        $pos = 0;
        foreach ($mm[1] as $blk) {
            $pos++;
            $num = preg_match('/^\s*PORTNUM\s*=\s*(\d+)/mi', $blk, $m) ? (int)$m[1] : $pos;
            $drv = preg_match('/^\s*DRIVER\s*=\s*(\S+)/mi', $blk, $m) ? strtoupper($m[1]) : '';
            $prt = preg_match('/^\s*PROTOCOL\s*=\s*(\S+)/mi', $blk, $m) ? strtoupper($m[1]) : '';
            if (in_array($drv, ['TELNET', 'BPQAXIP', 'AXIP'], true)) continue;            // not radio ports
            $p = ['num' => $num, 'name' => preg_match('/^\s*ID\s*=\s*(.+)$/mi', $blk, $m) ? mb_substr(trim($m[1]), 0, 40) : "Port $num",
                  'driver' => $drv ?: ($prt ?: '?'), 'interlock' => preg_match('/^\s*INTERLOCK\s*=\s*(\d+)/mi', $blk, $m) ? (int)$m[1] : null,
                  'host' => null, 'port' => null, 'kind' => null, 'ptt' => null, 'bw' => null];
            if (preg_match('/^\s*ADDR\s+(\S+)\s+(\d+)(.*)$/mi', $blk, $m)) {
                $p['host'] = $m[1]; $p['port'] = (int)$m[2]; $p['kind'] = 'tnc';
                if (preg_match('/\bPTT\s+(\w+)/i', $m[3], $x)) $p['ptt'] = strtoupper($x[1]);
            } elseif (preg_match('/^\s*IPADDR\s*=\s*(\S+)/mi', $blk, $ip) && preg_match('/^\s*TCPPORT\s*=\s*(\d+)/mi', $blk, $tp)) {
                $p['host'] = $ip[1]; $p['port'] = (int)$tp[1]; $p['kind'] = 'kiss';
            }
            if (preg_match('/^\s*BW(\d+)\s*$/mi', $blk, $m)) $p['bw'] = (int)$m[1];
            $bpq['ports'][$num] = $p;
        }
    }
    // RADIO n ... up to a "****" line: how LinBPQ reaches the rig
    if (preg_match_all('/^\s*RADIO\s+(\d+)\s*$(.*?)^\s*\*{3,}/smi', $text, $rr, PREG_SET_ORDER)) {
        foreach ($rr as $r) {
            $via = 'serial or other';
            if (preg_match('/^\s*FLRIG\s+([\w.\-]+:\d+)/mi', $r[2], $x)) $via = 'flrig ' . $x[1];
            elseif (preg_match('/^\s*HAMLIB\s*=?\s*([\w.\-]+:\d+)/mi', $r[2], $x)) $via = 'Hamlib ' . $x[1];
            elseif (preg_match('/^\s*RIGCTLD\s*=?\s*([\w.\-]+:\d+)/mi', $r[2], $x)) $via = 'rigctld ' . $x[1];
            $scan = preg_match_all('/^\s*\d+\s*,\s*[\d.]+\s*,/m', $r[2]);
            $bpq['radios'][(int)$r[1]] = ['num' => (int)$r[1], 'via' => $via, 'scan_freqs' => $scan];
        }
    }
} else {
    $note('info', 'LinBPQ\'s bpq32.cfg could not be read (config.php paths.linbpq), so LinBPQ\'s ports are not shown.');
}

// ── 5. The modem programs ──────────────────────────────────────────────────
function modem_kind(string $comm): array {
    $c = strtolower($comm);
    if (preg_match('/^varafm/', $c)) return ['vara', 'VARA FM'];
    if (preg_match('/^vara/', $c))   return ['vara', 'VARA HF'];
    if ($c === 'mercury')             return ['mercury', 'Mercury'];
    if (str_starts_with($c, 'data2g')) return ['data2g', 'Data2G'];
    if (str_starts_with($c, 'qtsoundmodem')) return ['qtsm', 'QtSoundModem'];
    if ($c === 'direwolf')            return ['direwolf', 'Direwolf'];
    if (preg_match('/^(soundmodem|sm\d*|uz7ho)/', $c)) return ['soundmodem', 'SoundModem'];
    if (str_starts_with($c, 'ardop')) return ['ardop', 'ARDOP'];
    if ($c === 'fldigi' || $c === 'flmsg') return ['fldigi', 'fldigi'];
    if (preg_match('/^(js8call|wsjtx|jt9|jtdx)/', $c)) return ['wsjt', 'WSJT/JS8'];
    if (preg_match('/^(pulseaudio|pipewire|wireplumber|jackd)/', $c)) return ['server', 'audio server'];
    return ['other', $comm];
}
$socks = [];
foreach ([am_path('/proc/net/tcp'), am_path('/proc/net/tcp6')] as $f) {
    $ls = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    array_shift($ls);
    foreach ($ls as $l) {
        $c = preg_split('/\s+/', trim($l));
        if (count($c) < 4) continue;
        $socks[] = ['lport' => hexdec(substr($c[1], strrpos($c[1], ':') + 1)), 'state' => $c[3]];
    }
}
$established = [];
foreach ($socks as $s) if ($s['state'] === '01') $established[$s['lport']] = true;
$shmCount = [];
foreach (($h['shm'] ?? []) as $s) $shmCount[(int)$s['key']] = (int)$s['nattch'];
// ALSA's dmix/dsnoop use their ipc_key and the next keys for their shared
// memory (dmix: key + 1 for the mixing buffer; dsnoop shows up as key + 2):
// a segment within key .. key + 2 belongs to that device, an exact match first.
$keyToPcm = [];
foreach ([2, 1, 0] as $off) {
    foreach ($pcms as $n => $p) if ($p['ipc_key'] !== null && in_array($p['type'], ['dmix', 'dsnoop', 'dshare'], true)) $keyToPcm[$p['ipc_key'] + $off] = $n;
}
foreach ($pcms as $n => $p) if ($p['ipc_key'] !== null) $keyToPcm[$p['ipc_key']] = $n;
$pcmAttached = [];                // device -> programs attached (the most on any of its segments)
foreach ($shmCount as $k => $c) if (isset($keyToPcm[$k])) $pcmAttached[$keyToPcm[$k]] = max($pcmAttached[$keyToPcm[$k]] ?? 0, $c);

$d2g = bpq_data2g_settings();
$d2gVol = preg_match('/--output-volume[ =](-?[\d.]+)/', (string)@file_get_contents(am_path($d2g['unit'])), $m) ? (float)$m[1] : null;
$mercIni = bpq_mercury_settings()['ini'];
$mercDev = null;
foreach ((array)@file(am_path($mercIni), FILE_IGNORE_NEW_LINES) as $l) if (preg_match('/^\s*output_device\s*=\s*(\S+)/', $l, $m)) $mercDev = $m[1];

// Is a port dropped from the network? (the Firewall page's read-only iptables rule)
$fw = $TEST_ROOT !== '' ? (string)@file_get_contents($TEST_ROOT . '/iptables.txt') : (string)shell_exec('sudo -n /usr/sbin/iptables -L -n --line-numbers -v 2>/dev/null');
$fw6 = $TEST_ROOT !== '' ? (string)@file_get_contents($TEST_ROOT . '/ip6tables.txt') : (string)shell_exec('sudo -n /usr/sbin/ip6tables -L -n --line-numbers -v 2>/dev/null');
$dropped = function (int $port, bool $v6 = false) use ($fw, $fw6): bool {
    foreach (preg_split('/\n/', $v6 ? $fw6 : $fw) as $l) {
        if (!preg_match('/\b(DROP|REJECT)\b/', $l)) continue;
        if (preg_match('/dpt:' . $port . '\b/', $l) && preg_match('/!\s*lo\b|\s!lo\s/', $l)) return true;
        if (preg_match('/dpts?:(\d+):(\d+)/', $l, $r) && $port >= (int)$r[1] && $port <= (int)$r[2] && preg_match('/!\s*lo\b/', $l)) return true;
        // multiport: "multiport dports 8400:8403,8500"
        if (preg_match('/dports\s+([\d:,]+)/', $l, $r) && preg_match('/!\s*lo\b/', $l)) {
            foreach (explode(',', $r[1]) as $part) {
                $ab = explode(':', $part);
                if ($port >= (int)$ab[0] && $port <= (int)($ab[1] ?? $ab[0])) return true;
            }
        }
    }
    return false;
};
$modems = [];
foreach (($h['holders'] ?? []) as $hd) {
    [$kind, $label] = modem_kind((string)$hd['comm']);
    $useCards = [];
    foreach ($hd['devices'] as $dv) {
        if (!preg_match('/^pcmC(\d+)D(\d+)([pc])$/', $dv, $m)) continue;
        $c = (int)$m[1];
        $useCards[$c] = $useCards[$c] ?? ['play' => false, 'cap' => false, 'via' => [], 'top' => null, 'exclusive' => true];
        $useCards[$c][$m[3] === 'p' ? 'play' : 'cap'] = true;
    }
    // shared through dmix / dsnoop: the SysV segments it is attached to
    foreach ($hd['shm_keys'] as $k) {
        $n = $keyToPcm[(int)$k] ?? null;
        if ($n === null) continue;
        foreach ($pcms[$n]['cards'] as $c) {
            if (!isset($useCards[$c])) continue;
            $useCards[$c]['via'][] = $n;
            $useCards[$c]['exclusive'] = false;
        }
    }
    // the named device it most likely opened: the top-most one leading to all of those
    foreach ($useCards as $c => &$u) {
        if (!$u['via']) continue;
        $best = null;
        foreach ($pcms as $n => $p) {
            if (!$p['named'] || array_diff($u['via'], $p['shares']) || !in_array($c, $p['cards'], true)) continue;
            $isSlave = false;
            foreach ($pcms as $q) foreach ($q['slaves'] as $s) if ($s['to'] === $n && $q['named'] && !array_diff($u['via'], $q['shares'])) $isSlave = true;
            if (!$isSlave) $best = $n;
        }
        $u['top'] = $best;
        $u['via'] = array_values(array_unique($u['via']));
    }
    unset($u);
    // Under Wine the program's sockets are held by wineserver (same user)
    if (!$hd['listen'] && preg_match('/\.exe$/i', (string)$hd['comm'])) {
        $exes = array_filter($h['holders'], fn($x) => $x['user'] === $hd['user'] && preg_match('/\.exe$/i', (string)$x['comm']));
        foreach ((array)($h['wine'] ?? []) as $w) {
            if ($w['user'] !== $hd['user']) continue;
            foreach ($w['listen'] as $l) {
                // with several Wine programs, VARA FM takes the ports of LinBPQ ports named "FM"
                if (count($exes) > 1) {
                    $bpName = '';
                    foreach ($bpq['ports'] as $bp) if ($bp['port'] === $l['port']) $bpName = $bp['name'];
                    $isFm = (bool)preg_match('/\bFM\b/i', $bpName);
                    if ($isFm !== (bool)preg_match('/^varafm/i', (string)$hd['comm'])) continue;
                }
                $hd['listen'][] = $l + ['wine' => true];
            }
        }
    }
    $ports = [];
    foreach ($bpq['ports'] as $bp) {
        foreach ($hd['listen'] as $l) if ($bp['port'] === $l['port']) $ports[] = $bp['num'];
    }
    $level = null;
    if ($kind === 'data2g' && $d2gVol !== null) $level = sprintf('%+.0f dB (--output-volume)', $d2gVol);
    $modems[] = ['pid' => $hd['pid'], 'comm' => $hd['comm'], 'kind' => $kind, 'label' => $label, 'user' => $hd['user'],
                 'unit' => $hd['unit'], 'cards' => $useCards, 'listen' => $hd['listen'], 'bpq_ports' => array_values(array_unique($ports)),
                 'level' => $level, 'configured' => $kind === 'mercury' ? $mercDev : null];
    // ports open on all interfaces: grouped per program, firewall checked
    $exp = ['ok' => [], 'v6' => [], 'open' => []];
    foreach ($hd['listen'] as $l) {
        if (!empty($l['conn']) || !in_array($l['addr'], ['0.0.0.0', '::'], true)) continue;
        if (!$ports && !in_array($kind, ['vara', 'mercury', 'data2g', 'qtsm', 'direwolf', 'soundmodem', 'ardop'], true)) continue;
        $v4 = $dropped((int)$l['port']);
        $v6 = $l['addr'] === '::' ? $dropped((int)$l['port'], true) : true;   // "::" takes IPv4 too (dual stack)
        $exp[$v4 && $v6 ? 'ok' : ($v4 ? 'v6' : 'open')][] = (int)$l['port'];
    }
    $pl = fn($a) => (count($a) > 1 ? 'ports ' : 'port ') . implode(', ', $a);
    if ($exp['open']) $note('warn', "$label listens on {$pl($exp['open'])} on all interfaces, and the firewall doesn't drop " . (count($exp['open']) > 1 ? 'them' : 'it')
        . ". A modem's TNC/KISS port has no password: drop it from the network (Admin → Firewall), or bind it to 127.0.0.1.");
    if ($exp['v6']) $note('warn', "$label listens on {$pl($exp['v6'])} over IPv4 and IPv6 (::). The IPv4 firewall drops " . (count($exp['v6']) > 1 ? 'them' : 'it')
        . ", the IPv6 one (ip6tables) doesn't. Add: " . implode('; ', array_map(fn($x) => "sudo ip6tables -I INPUT -p tcp --dport $x ! -i lo -j DROP", $exp['v6'])) . '; then sudo netfilter-persistent save');
    if ($exp['ok']) $note('ok', "$label listens on {$pl($exp['ok'])} on all interfaces; the firewall drops " . (count($exp['ok']) > 1 ? 'them' : 'it') . ' from the network.');
}

// ── 6. LinBPQ ports: who answers, attached? ────────────────────────────────
foreach ($bpq['ports'] as $num => &$bp) {
    $bp['modem'] = null; $bp['attached'] = null; $bp['listening'] = null;
    if ($bp['port'] === null) continue;
    foreach ($modems as $mi => $md) if (in_array($num, $md['bpq_ports'], true)) $bp['modem'] = $mi;
    $bp['attached'] = !empty($established[$bp['port']]);
    // listening, or already connected (VARA stops listening once LinBPQ is on)
    $listening = (bool)array_filter($socks, fn($s) => ($s['state'] === '0A' || $s['state'] === '01') && $s['lport'] === $bp['port']);
    $bp['listening'] = $listening;
    if (!$listening) {
        $note('warn', "LinBPQ port $num ({$bp['name']}) points to {$bp['host']}:{$bp['port']}, but nothing on this machine listens there: its modem isn't running"
            . (in_array(strtolower($bp['host']), ['127.0.0.1', 'localhost', '::1'], true) ? '.' : ' (or it is on another machine).'));
    } elseif (!$bp['attached']) {
        $note('warn', "LinBPQ port $num ({$bp['name']}): its modem listens on {$bp['port']} but LinBPQ is not connected to it. Restart LinBPQ.");
    }
}
unset($bp);

// ── 7. Radios: one per card the modems use; keyed by LinBPQ (RADIO n) or the modem ──
$radios = [];
foreach ($cards as $n => $c) {
    $users = array_keys(array_filter($modems, fn($m) => isset($m['cards'][$n])));
    if (!$users && !array_filter($pcms, fn($p) => $p['named'] && in_array($n, $p['cards'], true))) continue;
    $keyed = [];
    foreach ($users as $mi) {
        foreach ($modems[$mi]['bpq_ports'] as $pn) {
            $bp = $bpq['ports'][$pn];
            if ($bp['kind'] === 'tnc' && $bp['ptt'] && $bp['ptt'] !== 'NONE' && $bp['interlock'] !== null && isset($bpq['radios'][$bp['interlock']])) {
                $keyed['LinBPQ RADIO ' . $bp['interlock'] . ' (' . $bpq['radios'][$bp['interlock']]['via'] . ', PTT ' . $bp['ptt'] . ')'][] = $pn;
            } elseif ($bp['kind'] === 'tnc' && $bp['ptt']) {
                $keyed['LinBPQ (PTT ' . $bp['ptt'] . ')'][] = $pn;
            } else {
                $keyed[$modems[$mi]['label'] . ' itself (its own PTT setting)'][] = $pn;
            }
        }
    }
    // LinBPQ's keying first; a program with no LinBPQ port only when nothing else keys this radio
    if (!$keyed) foreach ($users as $mi) $keyed[$modems[$mi]['label'] . ' itself'][] = null;
    uksort($keyed, fn($a, $b) => (str_starts_with($b, 'LinBPQ') <=> str_starts_with($a, 'LinBPQ')) ?: strcmp($a, $b));
    $k = [];
    foreach ($keyed as $how => $ps) $k[] = ['how' => $how, 'ports' => array_values(array_filter($ps, fn($x) => $x !== null))];
    $radios[] = ['card' => $n, 'label' => $c['label'], 'keyed' => $k];
    $shared = array_filter($users, fn($mi) => !$modems[$mi]['cards'][$n]['exclusive']);
    $excl = array_filter($users, fn($mi) => $modems[$mi]['cards'][$n]['exclusive']);
    if ($excl && count($users) === 1) {
        $mi = reset($excl);
        $note('info', "{$modems[$mi]['label']} has card $n ({$c['name']}) to itself, without ALSA sharing (dmix/dsnoop). Fine while nothing else needs that card; "
            . 'a second program on it would need a shared device like the one in Installation Manual §10.17.');
    }
}
if (!$helper['ok']) {
    $note('info', $helper['error'] === 'not_installed'
        ? 'Which programs have which card open is not shown: the read-only helper /usr/local/sbin/bpq-dashboard-audio-map is not installed. update.sh installs it with the admin pages\' sudo rules (Installation Manual §11).'
        : 'Which programs have which card open is not shown: the web server may not run the read-only helper (sudo). Copy the package\'s www-data-tools.sudoers to /etc/sudoers.d/www-data-tools (Installation Manual §11).');
}

foreach ($modems as &$md) $md['cards'] = (object)$md['cards'];     // card numbers as keys, also for card 0
unset($md);
echo json_encode([
    'success' => true, 'generated_at' => gmdate('Y-m-d H:i:s'), 'helper' => $helper,
    'cards' => array_values($cards), 'pcms' => $pcms, 'conf_read' => (bool)$alsa,
    'modems' => $modems, 'shm' => $shmCount, 'attached' => (object)$pcmAttached, 'mixer' => $h['mixer'] ?? null,
    'bpq' => ['read' => $bpq['read'], 'ports' => array_values($bpq['ports']), 'radios' => array_values($bpq['radios'])],
    'radios' => $radios, 'notes' => $notes,
], JSON_UNESCAPED_SLASHES);
