<?php
/**
 * includes/web-login.php — second line of defence for endpoints that act as
 * the station (2026-10-04).
 *
 * The admin login itself is HTTP Basic Auth done by the web server (nginx
 * auth_basic / Apache AuthType Basic, set up by install.sh). These endpoints
 * refuse to act unless the web server says it checked the login, so a
 * hand-made or outdated web-server config can't leave them open by
 * accident, and a request that slips past a method-based rule (e.g. a GET
 * with a body under `limit_except GET`) is still refused.
 *
 * What counts as "the web server checked the login" (tested 2026-10-04):
 *  - nginx: ONLY the BPQ_WEB_LOGIN parameter, which the dashboard's nginx
 *    config sets (`fastcgi_param BPQ_WEB_LOGIN $remote_user;`) inside the
 *    locations that have auth_basic. nginx's REMOTE_USER is NOT proof: it
 *    is filled in from any Authorization header the visitor sends, even on
 *    a location with no login at all.
 *  - Apache: REMOTE_USER, which Apache sets only after AuthType Basic has
 *    checked the password.
 *  - Never PHP_AUTH_USER: PHP copies it from the visitor's header.
 * Under `limit_except GET { auth_basic … }` nginx only checks writes, so
 * those endpoints call this with $writesOnly = true: GET/HEAD are refused.
 *
 * Opt-out, for setups that protect the dashboard some other way (VPN-only,
 * an authenticating reverse proxy that doesn't pass the user name):
 *   config.php:  'require_web_login' => false,
 *
 * /includes/ is 403 on the web; PHP require only.
 */

require_once __DIR__ . '/station-config.php';

/** User name the web server authenticated, or '' if it didn't check one. */
function bpq_web_login_user(): string {
    $v = $_SERVER['BPQ_WEB_LOGIN'] ?? '';                 // nginx, set by our config
    if (is_string($v) && trim($v) !== '') return trim($v);
    $sw = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
    if (stripos($sw, 'nginx') === 0) return '';           // nginx REMOTE_USER is forgeable
    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $k) { // Apache, after AuthType Basic
        $v = $_SERVER[$k] ?? '';
        if (is_string($v) && trim($v) !== '') return trim($v);
    }
    return '';
}

/** True if this request may act as the station. */
function bpq_web_login_ok(bool $writesOnly = false): bool {
    if (PHP_SAPI === 'cli') return true;               // cron / sysop shell
    $m = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($writesOnly && ($m === 'GET' || $m === 'HEAD')) return false;
    if (bpq_web_login_user() !== '') return true;
    $cfg = bpq_config_all();
    if (array_key_exists('require_web_login', $cfg) && $cfg['require_web_login'] === false) {
        return true;                                    // explicit opt-out
    }
    return false;
}

/**
 * Stop with 403 (JSON) unless the request came through the admin login.
 * $what: short description for the error, e.g. 'Sending APRS messages'.
 * $writesOnly: also refuse GET/HEAD (endpoints behind `limit_except GET`).
 */
function bpq_require_web_login(string $what = 'This action', bool $writesOnly = false): void {
    if (bpq_web_login_ok($writesOnly)) return;
    http_response_code(403);
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => $what . ' needs the dashboard admin login, and the web server '
                   . "didn't ask for it. Protect this page in the web-server config "
                   . '(see the install guide), or set config.php '
                   . "'require_web_login' => false if you protect the dashboard another way.",
        'code'    => 'login_required',
    ]);
    exit;
}
