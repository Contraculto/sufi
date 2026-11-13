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

// Fetching remote actors is restricted to https + public IPs. Only turn this on
// for local development or the test suite (http://127.0.0.1).
$allow_private_fetch = false;

// Posts per page in /ap/outbox.
$outbox_page_size = 20;

// Post limits.
$max_post_chars   = 500;
$max_upload_bytes = 5 * 1024 * 1024;

// Ops: inbox retention (days), panel idle logout (seconds), login rate limit.
$inbox_retention_days = 90;
$session_lifetime     = 43200;
$login_max_attempts   = 5;

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
