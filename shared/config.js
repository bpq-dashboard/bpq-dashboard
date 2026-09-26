/**
 * BPQ Dashboard Client Configuration
 * Version: 1.3.0
 * 
 * This file provides configuration to the dashboard JavaScript.
 * It can be:
 *   1. Loaded dynamically from api/config.php (recommended)
 *   2. Edited manually for static deployments
 * 
 * SETUP: Edit the values below if not using api/config.php
 */

// Default configuration - override via api/config.php or edit here
const BPQ_CONFIG = {
    // =========================================================================
    // STATION INFORMATION
    // =========================================================================
    station: {
        callsign: 'N0CALL',      // Your callsign
        lat: 0.0,                 // Latitude
        lon: 0.0,                 // Longitude  
        grid: 'AA00aa'            // Grid square
    },
    
    // =========================================================================
    // API PATHS
    // =========================================================================
    paths: {
        logs: './logs/',
        data: './data/',
        api: './bbs-messages.php',
        nwsApi: './nws-bbs-post.php',
        configApi: './api/config.php',
        solarProxy: './solar-proxy.php'
    },
    
    // =========================================================================
    // FEATURES (set by server in public mode)
    // =========================================================================
    features: {
        bbsRead: true,
        bbsWrite: true,
        bbsBulletins: true,
        nwsAlerts: true,
        nwsPost: true,
        rfConnections: true,
        systemLogs: true,
        trafficStats: true,
        emailMonitor: true
    },
    
    // =========================================================================
    // UI SETTINGS
    // =========================================================================
    ui: {
        theme: 'auto',
        defaultMsgCount: 20,
        maxMsgCount: 100,
        refreshInterval: 60000,
        showVersion: true
    },
    
    // =========================================================================
    // NWS SETTINGS
    // =========================================================================
    nws: {
        defaultRegions: ['ALL'],
        defaultTypes: ['tornado', 'severe', 'winter'],
        autoRefresh: true,
        refreshInterval: 60000,
        postDestination: 'WX@ALLUS'
    },
    
    // =========================================================================
    // VERSION
    // =========================================================================
    version: '1.3.0',
    
    // =========================================================================
    // MODE (set by server)
    // =========================================================================
    mode: 'local'  // 'local' or 'public'
};

// =========================================================================
// CONFIGURATION LOADER
// =========================================================================

/**
 * Load configuration from server
 * Call this on page load to get server-side settings
 */
async function loadServerConfig() {
    try {
        const response = await fetch(BPQ_CONFIG.paths.configApi + '?t=' + Date.now());
        if (response.ok) {
            const serverConfig = await response.json();
            
            // Merge server config into BPQ_CONFIG
            if (serverConfig.station) {
                Object.assign(BPQ_CONFIG.station, serverConfig.station);
            }
            if (serverConfig.features) {
                Object.assign(BPQ_CONFIG.features, serverConfig.features);
            }
            if (serverConfig.paths) {
                Object.assign(BPQ_CONFIG.paths, serverConfig.paths);
            }
            if (serverConfig.ui) {
                Object.assign(BPQ_CONFIG.ui, serverConfig.ui);
            }
            if (serverConfig.nws) {
                Object.assign(BPQ_CONFIG.nws, serverConfig.nws);
            }
            if (serverConfig.mode) {
                BPQ_CONFIG.mode = serverConfig.mode;
            }
            
            console.log('BPQ Config loaded:', BPQ_CONFIG.mode, 'mode');
            return true;
        }
    } catch (e) {
        console.warn('Could not load server config, using defaults:', e.message);
    }
    return false;
}

/**
 * Check if a feature is enabled
 */
function isFeatureEnabled(feature) {
    return BPQ_CONFIG.features[feature] === true;
}

/**
 * Check if in public (read-only) mode
 */
function isPublicMode() {
    return BPQ_CONFIG.mode === 'public';
}

/**
 * Get station info
 */
function getStationInfo() {
    return BPQ_CONFIG.station;
}

/**
 * Update UI based on mode
 * Call after config loads to hide/disable write features in public mode
 */
function applyModeRestrictions() {
    if (isPublicMode() || !isFeatureEnabled('bbsWrite')) {
        // Hide compose/delete buttons
        document.querySelectorAll('[data-feature="bbs-write"]').forEach(el => {
            el.style.display = 'none';
        });
        document.querySelectorAll('.write-feature').forEach(el => {
            el.style.display = 'none';
        });
    }
    
    if (isPublicMode() || !isFeatureEnabled('nwsPost')) {
        // Hide NWS post buttons
        document.querySelectorAll('[data-feature="nws-post"]').forEach(el => {
            el.style.display = 'none';
        });
    }
    
    // Add public mode indicator
    if (isPublicMode()) {
        const indicator = document.createElement('span');
        indicator.className = 'public-mode-badge';
        indicator.textContent = '👁 View Only';
        indicator.title = 'This dashboard is in public read-only mode';
        indicator.style.cssText = 'background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;font-size:0.75rem;margin-left:8px;';
        
        const header = document.querySelector('h1, h2.gradient-text');
        if (header) {
            header.appendChild(indicator);
        }
    }
}

// =========================================================================
// AUTO-LOAD CONFIG ON DOM READY
// =========================================================================

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', async () => {
        await loadServerConfig();
        applyModeRestrictions();
    });
} else {
    // DOM already ready
    loadServerConfig().then(() => {
        applyModeRestrictions();
    });
}
