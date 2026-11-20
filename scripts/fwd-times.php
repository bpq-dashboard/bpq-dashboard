<?php
/**
 * scripts/fwd-times.php — show each forwarding partner's TIMES bands and the
 * hours none covers (v1.5.82). Read-only.
 *
 *   sudo php /var/www/bpq-dashboard/scripts/fwd-times.php [path/to/linmail.cfg]
 *
 * (sudo because linmail.cfg is usually readable by root only.)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/fwd-times.php';

$path = $argv[1] ?? bpq_linmail_path();
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Cannot read linmail.cfg (" . ($path ?: 'config.php paths.linbpq not set') . "). Give its path: fwd-times.php /path/to/linmail.cfg\n");
    exit(1);
}
$ps = bpq_fwd_partners((string)file_get_contents($path));
echo "Forwarding schedules in $path (times UTC)\n\n";
$bad = 0;
foreach ($ps as $name => $p) {
    $state = $p['enabled'] === false ? ' (disabled)' : '';
    if (!$p['bands']) { printf("  %-10s any time (no TIMES bands)%s\n", $name, $state); continue; }
    $gaps = bpq_fwd_gaps($p['bands']);
    printf("  %-10s bands %s%s\n", $name, implode(', ', array_map('bpq_fwd_span', $p['bands'])), $state);
    if ($gaps) {
        printf("  %-10s   NOT COVERED: %s\n", '', implode(', ', array_map('bpq_fwd_span', $gaps)));
        if ($p['enabled'] !== false) $bad++;
    }
}
echo $bad ? "\n$bad enabled partner(s) with hours not covered: no forwarding to them then.\n" : "\nNo gaps in enabled partners' schedules.\n";
exit(0);
