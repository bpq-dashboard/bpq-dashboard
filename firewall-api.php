<?php
/**
 * firewall-api.php — Firewall status API for BPQSERVER
 * Version: 1.5.5
 *
 * Returns iptables rules parsed into structured JSON.
 * Requires www-data to have sudo access to iptables -L -n --line-numbers
 *
 * Add to /etc/sudoers.d/www-data-iptables:
 *   www-data ALL=(ALL) NOPASSWD: /sbin/iptables -L -n --line-numbers -v
 *   www-data ALL=(ALL) NOPASSWD: /sbin/iptables -L INPUT -n --line-numbers -v
 *   www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status
 *   www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status sshd
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Auth — require BBS password ───────────────────────────────────────────────
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

// ── Port labels ───────────────────────────────────────────────────────────────
$PORT_LABELS = [
    '22'    => 'SSH (legacy)',
    '2222'  => 'SSH',
    '80'    => 'HTTP',
    '443'   => 'HTTPS',
    '21'    => 'FTP',
    '3389'  => 'RDP',
    '5903'  => 'VNC',
    '139'   => 'Samba NetBIOS',
    '445'   => 'Samba SMB',
    '8080'  => 'PAT Winlink',
    '8008'  => 'BPQ HTTP',
    '8010'  => 'BPQ Telnet',
    '8011'  => 'BPQ KISS',
    '8772'  => 'BPQ AX.25',
    '9025'  => 'VARA HF Cmd',
    '9026'  => 'VARA HF Data',
    '10000' => 'Webmin',
    '10'    => 'AX.25',
    '25'    => 'SMTP (BPQ)',
    '110'   => 'POP3 (BPQ)',
    '119'   => 'NNTP (BPQ)',
    '514'   => 'Syslog UDP',
    '520'   => 'RIP UDP',
    '631'   => 'CUPS (localhost)',
    '2947'  => 'GPSD (localhost)',
    '3306'  => 'MariaDB (localhost)',
    '3350'  => 'XRDP Sesman (localhost)',
    '5432'  => 'PostgreSQL (localhost)',
    '8999'  => 'FastCGI (localhost)',
    '10093' => 'VARA FM UDP',
];

// ── Run iptables ──────────────────────────────────────────────────────────────
function run_iptables() {
    $output = shell_exec('sudo /usr/sbin/iptables -L INPUT -n --line-numbers -v 2>&1');
    if (!$output) {
        $output = shell_exec('sudo /usr/sbin/iptables -L INPUT -n --line-numbers -v 2>&1');
    }
    return $output;
}

function run_fail2ban() {
    $status = shell_exec('sudo /usr/bin/fail2ban-client status 2>&1');
    $sshd   = shell_exec('sudo /usr/bin/fail2ban-client status sshd 2>&1');
    return ['status' => $status, 'sshd' => $sshd];
}

// ── Parse iptables output ─────────────────────────────────────────────────────
function parse_iptables(string $raw, array $portLabels): array {
    $lines  = explode("\n", $raw);
    $policy = 'UNKNOWN';
    $rules  = [];

    foreach ($lines as $line) {
        $line = trim($line);

        // Policy line
        if (preg_match('/^Chain INPUT \(policy (\w+)/', $line, $m)) {
            $policy = $m[1];
            continue;
        }

        // Skip headers
        if (preg_match('/^num|^pkts/', $line)) continue;
        if (empty($line)) continue;

        // Rule line: num pkts bytes target prot opt in out source destination [extra]
        // e.g.: 1    f2b-sshd   6    --  0.0.0.0/0  0.0.0.0/0  multiport dports 22
        if (!preg_match('/^\d+/', $line)) continue;

        $parts = preg_split('/\s+/', $line, 12);
        if (count($parts) < 9) continue;

        $num    = $parts[0];
        $pkts   = $parts[1];
        $bytes  = $parts[2];
        $target = $parts[3];
        $prot   = $parts[4];
        $source = $parts[7];
        $dest   = $parts[8];
        $extra  = $parts[11] ?? '';

        // Extract port
        $port = null;
        if (preg_match('/dpt[s]?:(\d+)/', $extra, $pm)) {
            $port = $pm[1];
        } elseif (preg_match('/dpts:(\d+):(\d+)/', $extra, $pm)) {
            $port = $pm[1] . '-' . $pm[2];
        } elseif (preg_match('/multiport dports ([\d,]+)/', $extra, $pm)) {
            $port = $pm[1];
        }

        // Classify
        $category = 'other';
        if ($target === 'ACCEPT') {
            if ($source === '0.0.0.0/0' || $source === '::/0') {
                $category = 'public-allow';
            } elseif (strpos($source, '10.0.0.') === 0 || strpos($source, '192.168.') === 0) {
                $category = 'lan-allow';
            } else {
                $category = 'ip-allow';
            }
        } elseif ($target === 'DROP' || $target === 'REJECT') {
            $category = 'block';
        } elseif (strpos($target, 'f2b-') === 0) {
            $category = 'fail2ban';
        }

        $label = $port ? ($portLabels[$port] ?? "Port $port") : null;

        $rules[] = [
            'num'      => (int)$num,
            'target'   => $target,
            'prot'     => $prot,
            'source'   => $source,
            'dest'     => $dest,
            'port'     => $port,
            'label'    => $label,
            'extra'    => $extra,
            'pkts'     => $pkts,
            'bytes'    => $bytes,
            'category' => $category,
        ];
    }

    return ['policy' => $policy, 'rules' => $rules];
}

// ── Parse fail2ban output ─────────────────────────────────────────────────────
function parse_fail2ban(array $raw): array {
    $result = ['jails' => [], 'banned_count' => 0, 'sshd_banned' => 0];

    if (preg_match('/Jail list:\s*(.+)/i', $raw['status'], $m)) {
        $result['jails'] = array_map('trim', explode(',', $m[1]));
    }

    if (preg_match('/Currently banned:\s*(\d+)/i', $raw['sshd'], $m)) {
        $result['sshd_banned'] = (int)$m[1];
    }
    if (preg_match('/Total banned:\s*(\d+)/i', $raw['sshd'], $m)) {
        $result['banned_count'] = (int)$m[1];
    }

    // Extract a sample of recently banned IPs (last 10)
    if (preg_match('/Banned IP list:\s*(.+)/i', $raw['sshd'], $m)) {
        $ips = array_slice(array_filter(explode(' ', trim($m[1]))), -10);
        $result['recent_banned'] = array_values($ips);
    }

    return $result;
}

// ── Build port summary ────────────────────────────────────────────────────────
function build_port_summary(array $rules, array $portLabels): array {
    $ports = [];

    foreach ($rules as $rule) {
        if (!$rule['port']) continue;
        $port = $rule['port'];
        if (!isset($ports[$port])) {
            $ports[$port] = [
                'port'    => $port,
                'label'   => $portLabels[$port] ?? "Port $port",
                'allowed' => [],
                'blocked' => false,
                'status'  => 'unknown',
            ];
        }

        if ($rule['target'] === 'ACCEPT') {
            $src = $rule['source'];
            if ($src === '0.0.0.0/0') {
                $ports[$port]['allowed'][] = 'Internet (all)';
                $ports[$port]['status'] = 'public';
            } elseif (strpos($src, '10.0.0.') === 0 || strpos($src, '192.168.') === 0) {
                $ports[$port]['allowed'][] = "LAN ($src)";
                if ($ports[$port]['status'] !== 'public') {
                    $ports[$port]['status'] = 'lan-only';
                }
            } else {
                $ports[$port]['allowed'][] = $src;
                if ($ports[$port]['status'] === 'unknown') {
                    $ports[$port]['status'] = 'ip-restricted';
                }
            }
        } elseif (in_array($rule['target'], ['DROP','REJECT'])) {
            $ports[$port]['blocked'] = true;
            if ($ports[$port]['status'] === 'unknown') {
                $ports[$port]['status'] = 'blocked';
            }
        }
    }

    // Sort by port number
    uksort($ports, function($a, $b) {
        return (int)$a - (int)$b;
    });

    return array_values($ports);
}

// ── Main ──────────────────────────────────────────────────────────────────────
$raw       = run_iptables();
$parsed    = parse_iptables($raw, $PORT_LABELS);
$f2b_raw   = run_fail2ban();
$f2b       = parse_fail2ban($f2b_raw);
$portSummary = build_port_summary($parsed['rules'], $PORT_LABELS);

echo json_encode([
    'policy'       => $parsed['policy'],
    'rules'        => $parsed['rules'],
    'port_summary' => $portSummary,
    'fail2ban'     => $f2b,
    'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    'raw'          => $raw,  // include raw for debugging
], JSON_PRETTY_PRINT);
