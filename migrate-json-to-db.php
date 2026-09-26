<?php
/**
 * migrate-json-to-db.php — ONE-TIME migration of the old JSON file storage
 * (data/messages/{folders,messages,addresses,rules,unread}.json) into the
 * new database tables (message-db.php / sql/2026-07-messages-schema.sql).
 *
 * Run once, from the CLI, on the server, AFTER running the schema SQL and
 * deploying message-db.php / message-storage.php:
 *
 *   cd /var/www/tprfn   (or wherever the dashboard lives)
 *   php migrate-json-to-db.php
 *
 * Safe to re-run: everything upserts/INSERT-IGNOREs, so running it twice
 * does not create duplicates. Does NOT delete the old JSON files — once
 * you've confirmed the data looks right in bbs-messages.html, you can
 * remove data/messages/*.json yourself.
 */

require_once __DIR__ . '/message-db.php';

// dashboardLog()/isFeatureEnabled()/apiError() come from includes/bootstrap.php
// normally, but this is a CLI script — provide no-op fallbacks if bootstrap
// wasn't already pulled in by message-db.php's requires.
if (!function_exists('dashboardLog'))    { function dashboardLog($l, $m, $c = []) { echo "[$l] $m\n"; } }
if (!function_exists('isFeatureEnabled')) { function isFeatureEnabled($f) { return true; } }
if (!function_exists('apiError'))        { function apiError($m, $c = 400) { fwrite(STDERR, "ERROR: $m\n"); exit(1); } }

function readJson($path, $default) {
    if (!file_exists($path)) return $default;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

$dir = __DIR__ . '/data/messages/';
echo "Reading JSON from: $dir\n";

// ---------------------------------------------------------------------
// Folders
// ---------------------------------------------------------------------
$folders = readJson($dir . 'folders.json', ['Inbox', 'Saved', 'Bulletins']);
$n = 0;
foreach ($folders as $f) {
    msgdb_folder_id(msgdb_sanitize_folder_name($f), true);
    $n++;
}
echo "Folders migrated: $n\n";

// ---------------------------------------------------------------------
// Addresses
// ---------------------------------------------------------------------
$addresses = readJson($dir . 'addresses.json', []);
$n = 0;
foreach ($addresses as $a) {
    try { msgdb_save_address($a); $n++; }
    catch (Throwable $e) { echo "  skip address '$a': " . $e->getMessage() . "\n"; }
}
echo "Addresses migrated: $n\n";

// ---------------------------------------------------------------------
// Messages (previously-saved/filed messages)
// ---------------------------------------------------------------------
$messages = readJson($dir . 'messages.json', []);
$n = 0;
foreach ($messages as $m) {
    try {
        msgdb_save_message($m, $m['folder'] ?? 'Saved');
        $n++;
    } catch (Throwable $e) {
        echo "  skip message '" . ($m['subject'] ?? '?') . "': " . $e->getMessage() . "\n";
    }
}
echo "Messages migrated: $n\n";

// ---------------------------------------------------------------------
// Rules / unread settings blobs
// ---------------------------------------------------------------------
$rules = readJson($dir . 'rules.json', null);
if ($rules !== null) {
    msgdb_set_setting('rules', $rules);
    echo "Rules config migrated (" . count($rules) . " rule(s))\n";
}

$unreadPath = $dir . 'unread.json';
if (file_exists($unreadPath)) {
    $unread = json_decode(file_get_contents($unreadPath), true);
    msgdb_set_setting('unread', $unread ?? (object)[]);
    echo "Unread map migrated\n";
}

echo "\nDone. Old JSON files left untouched at $dir — remove manually once verified.\n";
