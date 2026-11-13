<?php
namespace SUFI;

// ── Edit these for your instance ─────────────────────────────────────────────
$server    = 'example.com';
$username  = 'alice';
$password  = 'change-this-to-a-long-random-string';

// Set to false to disable the web panel at /sufi/
$panel_enabled = true;

// Profile shown to other servers. Only used when the Actor row is created for
// the first time; after that the database owns these fields.
$actor_name    = 'Alice';
$actor_summary = '';
$actor_icon    = '';

// Only send the session cookie over HTTPS. Turned off automatically when the
// request is plain HTTP (local `php -S` development).
$cookie_secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

// If all SUFI URLs live under a prefix (e.g. https://example.com/instance/ap/actor),
// set this to '/instance'. If activity URLs are at the domain root — the usual
// setup, where /ap/* and /.well-known/* are routed here — leave it empty.
$base_path = '';
$base_path = rtrim($base_path, '/');
$base_url  = 'https://' . $server . $base_path . '/';

// RSA private key — generate with:
//   openssl genrsa -out sys/private.pem 2048
// Then extract public key:
//   openssl rsa -in sys/private.pem -pubout -out sys/public.pem
$key_private = @file_get_contents(__DIR__ . '/private.pem');

// ── Log directory ─────────────────────────────────────────────────────────────
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0750, true); }
$directories = ['logs' => $log_dir];

// ── Parse incoming request body ───────────────────────────────────────────────
$input = file_get_contents('php://input');
$body  = json_decode($input, true) ?? [];

// ── Compatibility helpers ─────────────────────────────────────────────────────

/**
 * Build an associative array of request headers. Works on Apache (getallheaders)
 * and on PHP-FPM/Nginx by normalising $_SERVER entries.
 */
function get_request_headers(): array {
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace('_', '-', substr($key, 5));
            $headers[$name] = $value;
        } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $headers[str_replace('_', '-', $key)] = $value;
        }
    }
    return $headers;
}

/**
 * Read the configured API password from the current request (POST form or JSON body).
 */
function request_password(): ?string {
    global $body;
    return $_POST['password'] ?? ($body['password'] ?? null);
}
