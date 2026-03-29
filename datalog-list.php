<?php
/**
 * DataLog File Lister
 * Returns a list of DataLog*.txt files for the RF Power Monitor dashboard
 * 
 * Place this file in /var/www/tprfn/logs/ (same directory as your DataLog files)
 * Or adjust the $logDir path below
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Directory containing DataLog files - adjust if needed
$logDir = __DIR__;  // Same directory as this PHP file

// If this script is elsewhere, use absolute path:
// $logDir = '/var/www/tprfn/logs';

$files = [];
$error = null;

try {
    if (!is_dir($logDir)) {
        throw new Exception("Directory not found: $logDir");
    }
    
    // Find all DataLog*.txt files
    $pattern = $logDir . '/DataLog*.txt';
    $matches = glob($pattern);
    
    if ($matches === false) {
        throw new Exception("Error scanning directory");
    }
    
    foreach ($matches as $filepath) {
        $filename = basename($filepath);
        $stat = stat($filepath);
        $files[] = [
            'name' => $filename,
            'size' => $stat['size'],
            'modified' => date('Y-m-d H:i:s', $stat['mtime']),
            'mtime' => $stat['mtime']
        ];
    }
    
    // Sort by modification time, newest first
    usort($files, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });
    
    // Return results
    echo json_encode([
        'success' => true,
        'count' => count($files),
        'files' => $files,
        'latest' => count($files) > 0 ? $files[0]['name'] : null
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
