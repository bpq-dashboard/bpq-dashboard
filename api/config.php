<?php
/**
 * BPQ Dashboard Configuration API
 * Version: 1.3.0
 * 
 * Returns client-safe configuration as JSON
 * Used by shared/config.js to load server settings
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

// Only expose safe configuration values to client
$clientConfig = [
    // Station info (safe to expose)
    'station' => getConfig('station'),
    
    // Paths (safe to expose)
    'paths' => getConfig('paths'),
    
    // Features (tells client what's enabled)
    'features' => [
        'bbsRead'       => isFeatureEnabled('bbs_read'),
        'bbsWrite'      => isFeatureEnabled('bbs_write'),
        'bbsBulletins'  => isFeatureEnabled('bbs_bulletins'),
        'nwsAlerts'     => isFeatureEnabled('nws_alerts'),
        'nwsPost'       => isFeatureEnabled('nws_post'),
        'rfConnections' => isFeatureEnabled('rf_connections'),
        'systemLogs'    => isFeatureEnabled('system_logs'),
        'trafficStats'  => isFeatureEnabled('traffic_stats'),
        'emailMonitor'  => isFeatureEnabled('email_monitor'),
    ],
    
    // UI preferences
    'ui' => getConfig('ui'),
    
    // NWS settings (safe subset)
    'nws' => [
        'defaultRegions'  => getConfig('nws', 'default_regions', ['ALL']),
        'defaultTypes'    => getConfig('nws', 'default_types', ['tornado', 'severe', 'winter']),
        'autoRefresh'     => getConfig('nws', 'auto_refresh', true),
        'refreshInterval' => getConfig('nws', 'refresh_interval', 60000),
        'postDestination' => getConfig('nws', 'post_destination', 'WX@ALLUS'),
    ],
    
    // Mode indicator
    'mode' => isPublicMode() ? 'public' : 'local',
    
    // Version
    'version' => '1.3.0',
    
    // Server time (for sync)
    'serverTime' => date('c'),
    'serverTimeZone' => date_default_timezone_get(),
];

// Log config request
dashboardLog('debug', 'Config API accessed', ['mode' => $clientConfig['mode']]);

// Return as JSON
header('Content-Type: application/json');
echo json_encode($clientConfig, JSON_PRETTY_PRINT);
