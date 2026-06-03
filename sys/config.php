<?php
namespace SUFI;

// ── Edit these for your instance ─────────────────────────────────────────────
$server    = 'example.com';
$username  = 'alice';
$password  = 'change-this-to-a-long-random-string';

// Set to false to disable the web panel at /sufi/
$panel_enabled = true;

// If SUFI is deployed inside a subdirectory (e.g. /sufi/), set this to '/sufi'.
// If SUFI is at the domain root (e.g. /.well-known/webfinger → index.php), leave empty.
$base_path = '';

// RSA private key — generate with:
//   openssl genrsa -out sys/private.pem 2048
// Then extract public key:
//   openssl rsa -in sys/private.pem -pubout -out sys/public.pem
$key_private = file_get_contents(__DIR__ . '/private.pem');

// ── Log directory ─────────────────────────────────────────────────────────────
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0750, true); }
$directories = ['logs' => $log_dir];

// ── Parse incoming request body ───────────────────────────────────────────────
$input = file_get_contents('php://input');
$body  = json_decode($input, true) ?? [];
