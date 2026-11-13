<?php
// Quick health check for cron and for the deploy smoke test. Exits non-zero
// when something needs attention and records the reason in security.log.
//
//   php scripts/ops/health.php
//
// Cron runs it every 15 minutes silently; failures show up as Health.Failed
// lines in sys/logs/security.log.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);

require $root . '/sys/config.php';
require $root . '/sys/database.php';
require $root . '/sys/delivery.php';

$db      = SUFI\System\Database\connect_database();
$lines   = [];
$failed  = [];

$report = function (string $name, bool $ok, string $detail) use (&$lines, &$failed) {
    $lines[] = sprintf('  %-13s %s %s', $name, $ok ? 'ok  ' : 'WARN', $detail);
    if (!$ok) { $failed[] = $name; }
};

$lines[] = 'sufi health ' . date('c');

$integrity = (string) $db->query('PRAGMA quick_check')->fetchColumn();
$report('integrity', $integrity === 'ok', $integrity);

$version = (int) $db->query('PRAGMA user_version')->fetchColumn();
$report('schema', $version === SUFI\System\Database\SCHEMA_VERSION, "version {$version}");

$pending = (int) $db->query('SELECT COUNT(*) FROM Delivery')->fetchColumn();
$stuck   = (int) $db->query("SELECT COUNT(*) FROM Delivery WHERE next_try < datetime('now', '-1 hour')")->fetchColumn();
$report('delivery', $stuck === 0, "{$pending} pending, {$stuck} stuck");

$posts     = (int) $db->query('SELECT COUNT(*) FROM Post')->fetchColumn();
$followers = (int) $db->query('SELECT COUNT(*) FROM Follower')->fetchColumn();
$report('database', true, "{$posts} posts, {$followers} followers");

$uploads = $root . '/sys/uploads';
$report('uploads', is_dir($uploads) ? is_writable($uploads) : @mkdir($uploads, 0750, true), $uploads);

$free = @disk_free_space($root);
$report('disk', $free !== false && $free > 200 * 1024 * 1024,
    $free === false ? 'unknown' : round($free / 1073741824, 1) . 'G free');

$log   = $root . '/sys/logs/security.log';
$drops = 0;
if (is_file($log)) {
    foreach (array_slice(@file($log) ?: [], -2000) as $line) {
        if (str_contains($line, 'Delivery.Dropped')) { $drops++; }
    }
}
$lines[] = sprintf('  %-13s %s', 'recent drops', $drops);

echo implode("\n", $lines) . "\n";

if ($failed) {
    SUFI\save_log('Health.Failed', implode(',', $failed));
    echo 'problems: ' . implode(', ', $failed) . "\n";
    exit(1);
}

exit(0);
