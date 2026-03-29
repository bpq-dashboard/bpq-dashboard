# BPQ Dashboard Code Review & Recommendations

**Review Date:** February 2026  
**Version Reviewed:** 1.4.2  
**Overall Rating:** ⭐⭐⭐⭐ (4/5) - Production-ready with improvements recommended

---

## Recent Improvements (v1.4.2)

The following items from previous reviews have been addressed:

- ✅ **Hardcoded year "26"** - Replaced with dynamic year calculation in 3 locations
- ✅ **Navigation inconsistency** - "Email" renamed to "Messages" across all 6 pages  
- ✅ **Version number sync** - All pages now show v1.4.2
- ✅ **NWS Dashboard styling** - Rebuilt with consistent light theme matching other pages
- ✅ **Error categorization** - Added Timeout and Connect Failure tracking

---

## Executive Summary

The BPQ Dashboard is a well-designed, feature-rich monitoring suite for BPQ packet radio nodes. The codebase demonstrates good practices in many areas but has several opportunities for improvement, particularly around security, configuration management, and code organization.

**Strengths:**
- Modern, responsive UI with excellent mobile support
- Comprehensive feature set covering all aspects of BPQ monitoring
- Good error handling in most areas
- Well-structured HTML/CSS with consistent styling

**Areas for Improvement:**
- Security hardening needed for PHP backends
- Hardcoded values should be externalized to configuration
- Code duplication across dashboard pages
- Input validation gaps

---

## Critical Issues (Address Immediately)

### 1. Hardcoded Credentials in PHP

**File:** `bbs-messages.php` (lines 14-19)

```php
$config = [
    'bbs_host' => 'localhost',
    'bbs_port' => 8010,
    'bbs_user' => 'SYSOP',
    'bbs_pass' => 'password',  // ⚠️ CRITICAL
```

**Risk:** Default credentials committed to source control; may be deployed without changing.

**Recommendation:** 
- Move to environment variables or external config file
- Add startup check that fails if using default password
- Document requirement to change credentials prominently

```php
// Recommended approach
$config = [
    'bbs_pass' => getenv('BPQ_BBS_PASSWORD') ?: die('BPQ_BBS_PASSWORD environment variable required'),
];
```

### 2. CORS Wildcard Headers

**Files:** `bbs-messages.php`, `nws-bbs-post.php`, `solar-proxy.php`

```php
header('Access-Control-Allow-Origin: *');
```

**Risk:** Allows any website to make requests to your BBS backend, potentially exposing message data or enabling unauthorized actions.

**Recommendation:**
- Restrict to specific origins or same-origin only
- For local-only deployments, remove CORS headers entirely

```php
// Recommended: Same-origin only (remove header)
// Or specific domain:
header('Access-Control-Allow-Origin: https://yourdomain.com');
```

### 3. Input Validation Gaps

**File:** `bbs-messages.php` (line 656)

```php
$address = $_GET['address'] ?? '';
```

**Risk:** Bulletin address passed directly without validation could potentially be used in command injection if BBS processes it unsafely.

**Recommendation:**
- Validate callsign format with regex
- Sanitize all user inputs

```php
$address = $_GET['address'] ?? '';
if (!preg_match('/^[A-Z0-9@-]+$/i', $address)) {
    die(json_encode(['success' => false, 'error' => 'Invalid address format']));
}
$address = strtoupper(substr($address, 0, 50)); // Limit length
```

---

## High Priority Issues

### 4. Hardcoded Station Information

**File:** `bpq-rf-connections.html` (line 591)

```javascript
const HOME_STATION = { callsign: 'K1AJD', lat: 33.4735, lon: -82.0105, grid: 'EM83pl' };
```

**Impact:** Every user must edit the HTML file to customize.

**Recommendation:** Create a `config.js` file that users edit:

```javascript
// config.js (user edits this)
const BPQ_CONFIG = {
    homeStation: {
        callsign: 'YOURCALL',
        lat: 0.0,
        lon: 0.0,
        grid: 'AA00aa'
    },
    logPath: './logs/',
    refreshInterval: 60000
};
```

### 5. Test Endpoint Exposed in Production

**File:** `bbs-messages.php` (lines 665-666)

```php
case 'test':
    echo json_encode(['success' => true, 'message' => 'API working', 'config' => [...]]);
```

**Risk:** Exposes configuration details including host/port/username.

**Recommendation:**
- Remove or disable test endpoint in production
- Or require authentication for test endpoint

```php
case 'test':
    if (getenv('BPQ_DEBUG_MODE') !== 'true') {
        die(json_encode(['success' => false, 'error' => 'Test endpoint disabled']));
    }
    // ... rest of test logic
```

### 6. Inconsistent Version Numbers

**Current state:**
- `bpq-rf-connections.html`: v1.0.5
- `bpq-system-logs.html`: v1.0.4
- `bpq-traffic.html`: v1.0.4
- `bpq-email-monitor.html`: v1.0.4
- `nws-dashboard.html`: (no version in title)
- `bbs-messages.html`: (no version in title)
- `README.md`: v1.1.5

**Recommendation:**
- Implement single source of truth for version
- Update all files to consistent version
- Consider adding version to config.js

---

## Medium Priority Issues

### 7. Code Duplication Across Dashboards

**Issue:** Each HTML file contains duplicated code for:
- Navigation bar (~20 lines × 6 files)
- Status indicator styles
- Loading skeleton CSS
- Toast notification system
- Refresh button handling

**Recommendation:** Extract common components:

```
/shared/
  nav.html (or use JS to inject)
  styles.css
  utils.js (toast, status, loading)
```

### 8. No Rate Limiting on API Endpoints

**Files:** `bbs-messages.php`, `nws-bbs-post.php`

**Risk:** Endpoints can be abused with rapid requests, potentially overwhelming BBS connection.

**Recommendation:** Add simple rate limiting:

```php
session_start();
$rateKey = 'api_requests_' . date('Y-m-d-H-i');
$_SESSION[$rateKey] = ($_SESSION[$rateKey] ?? 0) + 1;
if ($_SESSION[$rateKey] > 60) { // 60 requests per minute
    http_response_code(429);
    die(json_encode(['error' => 'Rate limit exceeded']));
}
```

### 9. Missing Error Boundaries in JavaScript

**Issue:** Uncaught JavaScript errors can break entire dashboard.

**Recommendation:** Add global error handler:

```javascript
window.onerror = function(msg, url, line, col, error) {
    console.error('Dashboard error:', msg, 'at', url, line);
    showToast('An error occurred. Check console for details.', 'error');
    return false;
};
```

### 10. Synchronous localStorage Operations

**Issue:** Multiple dashboards use localStorage for settings, which is synchronous and can block rendering.

**Recommendation:** Consider using IndexedDB for larger data (saved messages, cached logs) or defer localStorage reads:

```javascript
// Defer non-critical localStorage reads
requestIdleCallback(() => {
    loadSavedSettings();
});
```

---

## Low Priority / Enhancements

### 11. Accessibility Improvements

**Issues:**
- Some buttons lack aria-labels
- Color contrast may be insufficient in dark mode sections
- Keyboard navigation incomplete

**Recommendations:**
```html
<button aria-label="Refresh data" class="modern-button">⟳</button>
```

### 12. Add Content Security Policy

**Recommendation:** Add CSP header to prevent XSS:

```html
<meta http-equiv="Content-Security-Policy" 
      content="default-src 'self'; 
               script-src 'self' 'unsafe-inline' cdn.tailwindcss.com cdn.jsdelivr.net unpkg.com; 
               style-src 'self' 'unsafe-inline' cdn.tailwindcss.com unpkg.com fonts.googleapis.com;
               img-src 'self' data: https:;
               connect-src 'self' api.weather.gov hamqsl.com">
```

### 13. Service Worker for Offline Support

**Enhancement:** Add service worker for basic offline functionality:
- Cache static assets
- Show cached data when offline
- Queue actions for when online

### 14. Add Unit Tests

**Current state:** No test coverage.

**Recommendation:** Add tests for:
- Log parsing functions
- Date/time formatting
- Region filtering logic
- API response handling

### 15. Bundle and Minify for Production

**Current state:** All code served as-is, CDN dependencies.

**Recommendation for larger deployments:**
- Bundle CSS/JS
- Minify for production
- Self-host critical dependencies

---

## Security Checklist

| Item | Status | Notes |
|------|--------|-------|
| Default credentials removed | ⚠️ | Needs attention |
| Input validation | ⚠️ | Partial - needs improvement |
| Output encoding | ✅ | JSON responses properly encoded |
| CORS policy | ⚠️ | Too permissive |
| HTTPS enforced | ℹ️ | Deployment dependent |
| Rate limiting | ❌ | Not implemented |
| Error handling | ✅ | Good coverage |
| Logging | ✅ | Comprehensive logging |
| Session management | ℹ️ | Uses localStorage (browser-side) |

---

## Performance Observations

**Positive:**
- CDN preconnect implemented ✅
- Deferred script loading ✅
- Chart animations disabled for speed ✅
- Debounced search inputs ✅
- Loading skeletons for perceived performance ✅

**Opportunities:**
- Log parsing could use Web Workers for large files
- Consider virtual scrolling for very long log lists
- Cache parsed log data (infrastructure exists but not fully utilized)

---

## Recommended Priority Order

1. **Immediate:** Remove/externalize hardcoded credentials
2. **This Week:** Add input validation, restrict CORS
3. **This Month:** Create external config file, centralize version
4. **Ongoing:** Refactor common code, add tests, accessibility

---

## Summary

The BPQ Dashboard is a solid, functional application that serves its purpose well. The main concerns are security-related and can be addressed with focused effort. The codebase is well-organized and maintainable, making these improvements straightforward to implement.

For amateur radio operators deploying on local networks, the current security posture may be acceptable. For any internet-facing deployment, the critical issues should be addressed before going live.
