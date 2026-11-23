<?php
/*
 * Shared security helpers for EvolvitCMS entry points.
 *
 * Optional configuration lives in cms/config.php (see config.sample.php).
 */

if (defined('EVOLVIT_SECURITY_LOADED')) return;
define('EVOLVIT_SECURITY_LOADED', true);

if (file_exists(__DIR__ . '/config.php')) include_once __DIR__ . '/config.php';
// installation defaults shipped with a project (config.php overrides them)
if (file_exists(__DIR__ . '/config.dist.php')) include_once __DIR__ . '/config.dist.php';

if (!defined('EVOLVIT_DEBUG')) define('EVOLVIT_DEBUG', getenv('EVOLVIT_DEBUG') === '1');
// Google OAuth client ids accepted for comment sign-in. When empty, ids declared in
// twigtemplates via <meta name="google-signin-client_id" content="..."> are accepted.
if (!defined('EVOLVIT_GOOGLE_CLIENT_IDS')) define('EVOLVIT_GOOGLE_CLIENT_IDS', '');
if (!defined('EVOLVIT_LOGIN_MAX_ATTEMPTS')) define('EVOLVIT_LOGIN_MAX_ATTEMPTS', 10);
if (!defined('EVOLVIT_LOGIN_WINDOW_SECONDS')) define('EVOLVIT_LOGIN_WINDOW_SECONDS', 600);
// Render templates inside the Twig sandbox (required when untrusted users can edit templates).
if (!defined('EVOLVIT_TWIG_SANDBOX')) define('EVOLVIT_TWIG_SANDBOX', false);
// Load templates from the `Twigs` stored query (name/html) instead of the twigtemplates directory.
if (!defined('EVOLVIT_TWIG_FROM_DB')) define('EVOLVIT_TWIG_FROM_DB', false);

// Never leak stack traces / SQL / paths to visitors unless explicitly debugging.
error_reporting(E_ALL);
ini_set('display_errors', EVOLVIT_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

function evolvit_is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') return true;
    return false;
}

/** Start the PHP session with hardened cookie settings. */
function evolvit_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // __Host- cookies can not be overwritten from sibling subdomains (cookie tossing)
    if (evolvit_is_https()) session_name('__Host-evolvitsess');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => evolvit_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ));
    session_start();
}

function evolvit_host_of($url) {
    $h = parse_url($url, PHP_URL_HOST);
    if (!$h) return null;
    $p = parse_url($url, PHP_URL_PORT);
    return strtolower($h) . ($p ? ':' . $p : '');
}

/**
 * CSRF protection: reject browser requests that come from another origin.
 * Browsers always send Origin on POST/fetch; Referer is used as a fallback.
 * Non-browser clients that send neither header are allowed (they carry no victim cookies).
 */
function evolvit_is_same_origin() {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
    $hostNoPort = preg_replace('/:\d+$/', '', $host);
    if (!empty($_SERVER['HTTP_SEC_FETCH_SITE']) && $_SERVER['HTTP_SEC_FETCH_SITE'] === 'cross-site') return false;
    $src = null;
    if (!empty($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== 'null') $src = $_SERVER['HTTP_ORIGIN'];
    elseif (!empty($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === 'null') return false;
    elseif (!empty($_SERVER['HTTP_REFERER'])) $src = $_SERVER['HTTP_REFERER'];
    if ($src === null) return true;
    $srcHost = evolvit_host_of($src);
    if ($srcHost === null) return false;
    return $srcHost === $host || $srcHost === $hostNoPort || preg_replace('/:\d+$/', '', $srcHost) === $hostNoPort;
}

function evolvit_require_same_origin() {
    if (!evolvit_is_same_origin()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(array('error' => 'Cross-origin request rejected'));
        exit;
    }
}

function evolvit_require_post() {
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
}

function evolvit_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
}

/**
 * Per-IP login throttling (session based throttling alone is trivially bypassed by
 * dropping the cookie). Returns true when the client is currently locked out.
 */
function evolvit_login_throttle_file() {
    $dir = sys_get_temp_dir() . '/evolvitcms-throttle';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . hash('sha256', __DIR__ . '|' . evolvit_client_ip());
}

function evolvit_login_is_locked() {
    $f = evolvit_login_throttle_file();
    $d = @json_decode(@file_get_contents($f), true);
    if (!is_array($d)) return false;
    if (time() - $d['ts'] > EVOLVIT_LOGIN_WINDOW_SECONDS) return false;
    return $d['n'] >= EVOLVIT_LOGIN_MAX_ATTEMPTS;
}

function evolvit_login_failed() {
    $f = evolvit_login_throttle_file();
    $d = @json_decode(@file_get_contents($f), true);
    if (!is_array($d) || time() - $d['ts'] > EVOLVIT_LOGIN_WINDOW_SECONDS) $d = array('n' => 0, 'ts' => time());
    $d['n']++;
    @file_put_contents($f, json_encode($d), LOCK_EX);
}

function evolvit_login_succeeded() {
    @unlink(evolvit_login_throttle_file());
}

/** SQL identifiers (table / column names) that may be spliced into SQL text. */
function evolvit_is_identifier($s) {
    return is_string($s) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $s) === 1;
}

/** Google OAuth client ids we accept tokens for. */
function evolvit_google_client_ids() {
    $ids = array_filter(array_map('trim', explode(',', EVOLVIT_GOOGLE_CLIENT_IDS)));
    if ($ids) return $ids;
    $dir = dirname(__DIR__) . '/twigtemplates';
    foreach (glob($dir . '/*.twig') ?: array() as $f) {
        if (preg_match_all('/google-signin-client_id"\s+content="([^"]+)"/', file_get_contents($f), $m))
            $ids = array_merge($ids, $m[1]);
    }
    return array_values(array_unique($ids));
}

function evolvit_json_error($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array('error' => $msg));
    exit;
}

/**
 * Verify a Google ID token. Returns the token claims, or null when the token is invalid or
 * was not issued for one of our client ids (without the audience check any Google token
 * minted for any other application would be accepted).
 */
function evolvit_google_verify_token($idtoken) {
    $idtoken = (string)$idtoken;
    if ($idtoken === '' || !preg_match('/^[A-Za-z0-9_\-.]+$/', $idtoken)) return null;
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_URL => 'https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($idtoken),
    ));
    $resp = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $d = json_decode((string)$resp, true);
    if ($status !== 200 || !is_array($d)) return null;
    if (!in_array(isset($d['aud']) ? $d['aud'] : '', evolvit_google_client_ids(), true)) return null;
    if (!in_array(isset($d['iss']) ? $d['iss'] : '', array('accounts.google.com', 'https://accounts.google.com'), true)) return null;
    if (!isset($d['exp']) || (int)$d['exp'] < time()) return null;
    if ((isset($d['email_verified']) ? (string)$d['email_verified'] : '') !== 'true') return null;
    return $d;
}

/** Store verified Google identity in the session (after regenerating the session id). */
function evolvit_google_login($claims) {
    session_regenerate_id(true);
    foreach (array('sub', 'email', 'picture', 'given_name', 'family_name', 'locale') as $k)
        $_SESSION['_session_google_user_'.$k] = isset($claims[$k]) ? (string)$claims[$k] : '';
}
