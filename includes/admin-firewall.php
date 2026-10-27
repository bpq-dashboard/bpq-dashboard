<?php
/**
 * includes/admin-firewall.php — iptables / ip6tables output parser shared by
 * admin/firewall-api.php and admin/system-audit-api.php (2026-10-25).
 * /includes/ is 403 on the web; PHP require only.
 *
 * Input: `iptables -L -n --line-numbers -v` (all chains, so rules that ufw or
 * fail2ban keep in their own chains are followed too).
 *
 * The column layout differs between iptables builds (legacy prints an "opt"
 * column of "--", newer nft builds may leave it out), so fields are found by
 * what they look like instead of by position:
 *   num pkts bytes target prot [opt] in out source destination [match…]
 */

function bpq_fw_is_addr(string $t): bool {
    return (bool)preg_match('#^(?=.*[.:])[0-9a-fA-F.:]+(/\d{1,3})?$#', $t);
}

/** Private / local address or network (RFC 1918, CGNAT, link-local, ULA). */
function bpq_fw_is_lan(string $src): bool {
    $ip = explode('/', $src)[0];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['100.64.0.0', 10], ['169.254.0.0', 16]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($n & $mask) === (ip2long($net) & $mask)) return true;
        }
        return false;
    }
    return (bool)preg_match('/^(f[cd]|fe[89ab])/i', $ip);   // fc00::/7 ULA, fe80::/10 link-local
}

function bpq_fw_is_loopback(string $a): bool {
    return (bool)preg_match('#^(127\.|::1(/128)?$)#', $a);
}

function bpq_fw_is_any(string $a): bool {
    return $a === '0.0.0.0/0' || $a === '::/0';
}

/** One rule line → array, or null. */
function bpq_fw_parse_rule(string $line): ?array {
    $t = preg_split('/\s+/', trim($line));
    if (count($t) < 8 || !ctype_digit($t[0])) return null;
    $si = null;
    for ($i = 5; $i < count($t) - 1; $i++) {
        if (bpq_fw_is_addr($t[$i]) && bpq_fw_is_addr($t[$i + 1])) { $si = $i; break; }
    }
    if ($si === null) return null;
    $extra = implode(' ', array_slice($t, $si + 2));
    $ports = [];
    if (preg_match('/multiport dports ([\d,:]+)/', $extra, $pm)) {
        foreach (explode(',', $pm[1]) as $r) {
            [$a, $b] = array_pad(explode(':', $r), 2, null);
            $ports[] = [(int)$a, (int)($b ?? $a)];
        }
    } elseif (preg_match('/\bdpts?:(\d+)(?::(\d+))?/', $extra, $pm)) {
        $ports[] = [(int)$pm[1], (int)($pm[2] ?? $pm[1])];
    }
    return [
        'num'    => (int)$t[0],
        'pkts'   => $t[1],
        'bytes'  => $t[2],
        'target' => $t[3],
        'prot'   => $t[4],
        'in'     => $t[$si - 2] ?? '*',
        'out'    => $t[$si - 1] ?? '*',
        'source' => $t[$si],
        'dest'   => $t[$si + 1],
        'extra'  => $extra,
        'ports'  => $ports,
        'port'   => $ports ? implode(',', array_map(fn($p) => $p[0] === $p[1] ? (string)$p[0] : "{$p[0]}-{$p[1]}", $ports)) : null,
    ];
}

/**
 * Every chain in the listing.
 * @return array<string, array{policy:?string, rules:array}>
 */
function bpq_fw_parse_all(string $raw): array {
    $chains = []; $cur = null;
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^Chain (\S+)(?: \(policy (\w+))?/', trim($line), $m)) {
            $cur = $m[1];
            $chains[$cur] = ['policy' => $m[2] ?? null, 'rules' => []];
            continue;
        }
        if ($cur !== null && ($r = bpq_fw_parse_rule($line))) $chains[$cur]['rules'][] = $r;
    }
    return $chains;
}

/**
 * Does rule $r match a NEW tcp connection from a public address to $port on
 * this host?  Returns true / false, or null = "can't tell" (a match we don't
 * interpret).
 */
function bpq_fw_rule_matches(array $r, int $port): ?bool {
    if ($r['in'] === 'lo') return false;
    if (!in_array($r['prot'], ['all', '0', 'tcp', '6'], true)) return false;
    if (!bpq_fw_is_any($r['source'])) return false;                     // only from listed addresses
    if ($r['ports']) {
        $hit = false;
        foreach ($r['ports'] as [$a, $b]) if ($port >= $a && $port <= $b) { $hit = true; break; }
        if (!$hit) return false;
    }
    $x = preg_replace('#/\*.*?\*/#', '', $r['extra']);                   // comments
    $x = preg_replace('/\b(tcp|multiport)\b|\bdpts?:[\d:,]+|\bdports [\d:,]+|\bspts?:[\d:]+/', '', $x);
    if (preg_match('/\b(ctstate|state)\s+(\S+)/', $x, $m)) {
        if (!preg_match('/\bNEW\b/', $m[2])) return false;              // ESTABLISHED / RELATED / INVALID only
        $x = str_replace($m[0], '', $x);
    }
    if (preg_match('/ADDRTYPE match dst-type (\S+)/', $x, $m)) {
        if ($m[1] !== 'LOCAL') return false;                            // BROADCAST / MULTICAST
        $x = str_replace($m[0], '', $x);
    }
    $x = preg_replace('/\blimit: .*$|\bflags:\S+/', '', $x);
    return trim($x) === '' ? true : null;
}

/**
 * Is $port reachable from the internet? Follows jumps from INPUT (ufw,
 * fail2ban, custom chains) like iptables does. Unknown matches lean towards
 * "reachable" for ACCEPT and "not blocking" for DROP, so the answer errs on
 * the side of warning.
 */
function bpq_fw_port_public(array $chains, int $port, string $chain = 'INPUT', int $depth = 0): ?bool {
    if (!isset($chains[$chain]) || $depth > 10) return null;
    foreach ($chains[$chain]['rules'] as $r) {
        $m = bpq_fw_rule_matches($r, $port);
        if ($m === false) continue;
        $tg = $r['target'];
        if ($tg === 'ACCEPT') return true;
        if ($m === null) continue;                                      // unsure: don't let it block
        if ($tg === 'DROP' || $tg === 'REJECT') return false;
        if ($tg === 'RETURN') return null;
        if (isset($chains[$tg])) {                                      // jump into another chain
            $sub = bpq_fw_port_public($chains, $port, $tg, $depth + 1);
            if ($sub !== null) return $sub;
        }
        // LOG and other non-terminating targets: keep going
    }
    if ($depth > 0) return null;                                        // fell off a user chain → back to caller
    $pol = $chains[$chain]['policy'] ?? 'ACCEPT';
    return !in_array($pol, ['DROP', 'REJECT'], true);
}

/** True when ufw is managing the rules (its chains are present). */
function bpq_fw_is_ufw(array $chains): bool {
    foreach (array_keys($chains) as $c) if (strpos($c, 'ufw-') === 0) return true;
    return false;
}
