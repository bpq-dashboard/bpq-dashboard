# BPQ Dashboard Security Audit

**Audit Date:** March 2026
**Version Audited:** 1.4.2
**Auditor:** AI-assisted review of source code and configuration

---

## Executive Summary

The BPQ Dashboard has a solid foundation for local network use with a well-designed public/local mode system. However, several vulnerabilities exist that should be addressed before any internet-facing deployment, and some improvements are recommended even for LAN-only installations.

**Risk Rating:** Moderate for LAN use, High for internet-facing deployment without remediation.

---

## Existing Security Controls

The dashboard already includes several security measures documented in `PUBLIC-DEPLOYMENT.md` and implemented in `includes/bootstrap.php`:

- **Public/Local mode toggle** — public mode disables writes and enforces rate limiting
- **Rate limiting** — 30 requests/minute per IP when enabled (public mode)
- **CORS configuration** — configurable origin restrictions
- **BBS authentication** — SHA-256 hashed password stored in `data/.bbs_auth`
- **Bootstrap direct-access prevention** — returns 403 if accessed directly
- **Input sanitization** — `message-storage.php` has `sanitizeFolderName()` and `validateAddress()`
- **Config separation** — `api/config.php` only exposes client-safe values

---

## Findings

### CRITICAL — Fix Before Any Internet Exposure

#### C1. No .htaccess File in Document Root

**Risk:** Directory listing exposure, direct access to sensitive files.

**Current State:** No `.htaccess` file exists in the dashboard root directory. The `cache/` directory has a `.htaccess` but the root does not.

**Impact:** Depending on Apache configuration, an attacker may be able to browse directory contents, download config files, access log data, or view `.example` files containing configuration templates.

**Remediation:** Create `.htaccess` in the dashboard root:

```apache
# Disable directory browsing
Options -Indexes

# Security headers
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# Block direct access to sensitive files
<FilesMatch "\.(php\.example|log|bak|config|md|sh|bat|txt)$">
    Require all denied
</FilesMatch>

# Allow specific PHP files that need direct access
<FilesMatch "^(bbs-messages|station-storage|message-storage|callsign-lookup|solar-proxy|health-check|datalog-list|nws-bbs-post)\.php$">
    Require all granted
</FilesMatch>

# Allow API directory
<Files "data.php">
    Require all granted
</Files>

# Block config files absolutely
<FilesMatch "^(config|bbs-config|nws-config|tprfn-config)\.php$">
    Require all denied
</FilesMatch>

# Block includes directory
<Directory "includes">
    Require all denied
</Directory>
```

#### C2. CORS Wildcard in solar-proxy.php

**File:** `solar-proxy.php` line 27

**Current State:** `Access-Control-Allow-Origin: *` is set when CORS configuration is not available. The bootstrap CORS logic is not used because solar-proxy.php may not include bootstrap.php.

**Impact:** Any website can make requests to your solar proxy, potentially using it as an open proxy.

**Remediation:** Include bootstrap.php or replicate its CORS logic:
```php
require_once __DIR__ . '/includes/bootstrap.php';
// CORS is now handled by bootstrap
```

#### C3. station-storage.php Accepts Unauthenticated Writes

**File:** `station-storage.php`

**Current State:** POST requests to save locations, partners, and import data have no authentication check. Anyone who can reach the endpoint can overwrite all station location data.

**Impact:** An attacker could corrupt or delete station location data, inject false locations, or fill storage.

**Remediation:** Add authentication check for write operations:
```php
if ($method === 'POST') {
    if (isPublicMode()) {
        apiError('Write operations disabled in public mode', 403);
    }
    // ... existing POST handling
}
```

#### C4. datalog-list.php Exposes Server File Information

**File:** `datalog-list.php`

**Current State:** Returns full filenames, sizes, and modification timestamps for all DataLog files. No authentication required. Also exposes the server directory path in error messages.

**Impact:** Information disclosure — reveals server file structure, data collection schedule, and system timing.

**Remediation:** Remove directory path from error messages, add access controls:
```php
throw new Exception("Directory not found");  // Don't include $logDir
```

#### C5. health-check.php Exposes System Information

**File:** `health-check.php`

**Current State:** Returns PHP version, loaded extensions, file paths, and system configuration to any requester.

**Impact:** Gives attackers a detailed map of the server environment.

**Remediation:** Require authentication or restrict to localhost:
```php
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    http_response_code(403);
    die(json_encode(['error' => 'Local access only']));
}
```

---

### HIGH — Fix Before Public Deployment

#### H1. api/data.php Accepts Unvalidated Timezone Input

**File:** `api/data.php` lines 121-135

**Current State:** `$_GET['tz']` and `$_GET['tz_offset']` are used to construct timezone objects without strict validation. While `DateTimeZone` constructor will reject invalid strings, the error handling path may leak information.

**Remediation:** Validate against known timezone list:
```php
if (!empty($_GET['tz']) && in_array($_GET['tz'], DateTimeZone::listIdentifiers())) {
    $datalogTz = $_GET['tz'];
}
```

#### H2. api/data.php Debug Mode Exposes Internals

**File:** `api/data.php` line 42

**Current State:** `?debug=1` parameter triggers verbose output including file paths, parsing details, and diagnostic information.

**Impact:** Information disclosure to anyone who knows the parameter.

**Remediation:** Restrict debug mode to local access:
```php
$debug = isset($_GET['debug']) && ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1');
```

#### H3. No Content-Security-Policy Header

**Current State:** No CSP headers set on any page. All HTML files load scripts from CDNs (Tailwind, Chart.js, Leaflet).

**Impact:** XSS attacks could inject arbitrary scripts. CDN compromise would affect all users.

**Remediation:** Add CSP meta tag to each HTML file:
```html
<meta http-equiv="Content-Security-Policy"
      content="default-src 'self';
               script-src 'self' 'unsafe-inline' cdn.tailwindcss.com cdn.jsdelivr.net unpkg.com cdnjs.cloudflare.com;
               style-src 'self' 'unsafe-inline' cdn.tailwindcss.com unpkg.com fonts.googleapis.com;
               img-src 'self' data: https: blob:;
               connect-src 'self' services.swpc.noaa.gov www.hamqsl.com api.weather.gov;
               font-src 'self' fonts.gstatic.com;">
```

#### H4. BBS Password Stored as SHA-256 Without Salt Rotation

**File:** `bbs-messages.php` lines 30-52

**Current State:** Password is hashed with SHA-256 using a fixed salt: `AUTH_SALT = 'bpq_dashboard_2024'`. The salt is hardcoded and the same for every installation.

**Impact:** All BPQ Dashboard installations use the same salt, making rainbow table attacks feasible across installations.

**Remediation:** Generate unique salt per installation:
```php
// On first setup, generate and store unique salt
$saltFile = __DIR__ . '/data/.auth_salt';
if (!file_exists($saltFile)) {
    file_put_contents($saltFile, bin2hex(random_bytes(32)));
}
define('AUTH_SALT', file_get_contents($saltFile));
```

Or better, use `password_hash()` which handles salting automatically:
```php
function hashPasswordServer($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}
```

#### H5. Session-Based Rate Limiting is Bypassable

**File:** `includes/bootstrap.php` lines 179-193

**Current State:** Rate limiting uses PHP sessions (`$_SESSION`). An attacker can bypass this by not sending session cookies, or by clearing cookies between requests.

**Remediation:** Use IP-based rate limiting with file-based storage:
```php
$rateFile = sys_get_temp_dir() . '/bpq_rate_' . md5($_SERVER['REMOTE_ADDR']);
$rateData = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) : ['count' => 0, 'window' => time()];
if (time() - $rateData['window'] > 60) {
    $rateData = ['count' => 0, 'window' => time()];
}
$rateData['count']++;
file_put_contents($rateFile, json_encode($rateData));
if ($rateData['count'] > 30) {
    http_response_code(429);
    die(json_encode(['error' => 'Rate limit exceeded']));
}
```

---

### MEDIUM — Recommended Improvements

#### M1. BBS Telnet Connection Has No TLS

**File:** `bbs-messages.php` lines 150-170

**Current State:** BBS connection uses plain TCP sockets (`fsockopen`) to connect to BPQ telnet port. Credentials are sent in cleartext.

**Impact:** On shared networks, BBS credentials could be sniffed. Minimal risk when connecting to localhost.

**Recommendation:** Document that BBS host should always be localhost. If remote BBS access is needed, use SSH tunnel or VPN.

#### M2. Log Files Served as Static Content

**Current State:** Log files in `logs/` directory are `.txt` files that may be directly accessible via web browser.

**Remediation:** Add to `.htaccess`:
```apache
<Directory "logs">
    Require all denied
</Directory>
```
Or create `logs/.htaccess`:
```apache
Require all denied
```

#### M3. CDN Dependencies Not Pinned

**Current State:** HTML files load from CDNs without subresource integrity (SRI) hashes:
```html
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
```

**Impact:** CDN compromise would allow arbitrary code execution.

**Recommendation:** Add SRI hashes or self-host critical libraries:
```html
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
        integrity="sha384-XXXX" crossorigin="anonymous"></script>
```

#### M4. localStorage Stores Sensitive Data

**Current State:** `bpq_callsign_cache` stores QRZ lookup results including names, addresses, and grid squares in browser localStorage with 7-day expiry.

**Impact:** Any script on the same origin can read this data. Low risk for single-purpose dashboard.

**Recommendation:** Acceptable for current use case. If concerned, reduce cache TTL or move to sessionStorage.

#### M5. data/stations/ Directory May Be Browsable

**Current State:** Station location data stored as JSON files. Directory may be listable.

**Remediation:** Add `data/.htaccess`:
```apache
Require all denied
```

---

### LOW — Best Practices

#### L1. Server Version Headers

Apache and PHP often expose version information in HTTP response headers.

**Remediation:**
```apache
# In Apache config
ServerTokens Prod
ServerSignature Off
```
```ini
# In php.ini
expose_php = Off
```

#### L2. Missing HTTP Strict Transport Security

If using HTTPS, add HSTS header:
```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

#### L3. Error Display in Production

Ensure PHP errors are not displayed to users:
```ini
# In php.ini
display_errors = Off
log_errors = On
error_log = /var/log/php_errors.log
```

#### L4. File Permission Review

Recommended permissions:
```
chmod 644 *.html *.md *.svg          # Read-only for web
chmod 640 config.php bbs-config.php  # Owner+group read, no world
chmod 750 scripts/ includes/         # No web access
chmod 770 cache/ data/ logs/         # Write access for PHP
```

---

## Security Hardening Checklist

### Before Any Deployment
- [ ] Copy config.php.example to config.php and change ALL default credentials
- [ ] Set `'test_endpoint' => false` in config.php features
- [ ] Set proper file permissions (see L4)
- [ ] Verify config.php is not web-accessible

### For LAN-Only Deployment
- [ ] All items above
- [ ] Create root `.htaccess` with directory listing disabled (C1)
- [ ] Add `logs/.htaccess` and `data/.htaccess` with `Require all denied` (M2, M5)
- [ ] Restrict health-check.php to localhost (C5)

### For Internet-Facing Deployment
- [ ] All items above
- [ ] Set `'security_mode' => 'public'` in config.php
- [ ] Configure CORS with specific origins (C2)
- [ ] Enable HTTPS with Let's Encrypt
- [ ] Enable HTTP Basic Auth (see PUBLIC-DEPLOYMENT.md)
- [ ] Add authentication to station-storage.php writes (C3)
- [ ] Restrict debug mode to localhost (H2)
- [ ] Add Content-Security-Policy headers (H3)
- [ ] Upgrade password hashing to bcrypt (H4)
- [ ] Implement IP-based rate limiting (H5)
- [ ] Add HSTS header (L2)
- [ ] Configure fail2ban
- [ ] Configure firewall (ports 443 only, plus SSH)
- [ ] Disable PHP error display (L3)
- [ ] Hide server version headers (L1)
- [ ] Add SRI hashes to CDN scripts (M3)

---

## Files Requiring Changes

| File | Findings | Priority |
|------|----------|----------|
| *(new)* `.htaccess` | Create with security rules | Critical |
| *(new)* `logs/.htaccess` | Block direct access | Medium |
| *(new)* `data/.htaccess` | Block direct access | Medium |
| `station-storage.php` | Add auth for writes | Critical |
| `solar-proxy.php` | Use bootstrap CORS | Critical |
| `datalog-list.php` | Remove path from errors | Critical |
| `health-check.php` | Restrict to localhost | Critical |
| `api/data.php` | Validate tz input, restrict debug | High |
| `bbs-messages.php` | Upgrade to bcrypt hashing | High |
| `includes/bootstrap.php` | IP-based rate limiting | High |
| All HTML files | Add CSP meta tag | High |

---

## Existing Documentation Coverage

| Document | Security Content |
|----------|-----------------|
| `PUBLIC-DEPLOYMENT.md` | HTTPS setup, public mode, basic auth, firewall, fail2ban, security checklist |
| `CODE-REVIEW.md` | Hardcoded credentials, CORS, input validation, test endpoint |
| `config.php.example` | Security mode, rate limiting, CORS settings, feature toggles |
| `INSTALL.md` | Basic credential setup |
| `INSTALL-LINUX.md` | File permissions, service configuration |
| `INSTALL-WINDOWS.md` | Basic setup only |

**Gap:** No standalone security hardening guide. `PUBLIC-DEPLOYMENT.md` covers the basics but misses the findings in this audit. `CODE-REVIEW.md` identifies issues but doesn't provide a complete remediation plan.

**Recommendation:** Merge relevant parts of this audit into `PUBLIC-DEPLOYMENT.md` and update `CODE-REVIEW.md` with current status.
