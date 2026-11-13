<?php
// Drop inbox items older than $inbox_retention_days. Run daily from cron.
// The inbox is a public endpoint, so without this the table grows forever.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);

require $root . '/sys/config.php';
require $root . '/sys/database.php';

$db_handler = SUFI\System\Database\connect_database();
$days       = max(1, (int) ($inbox_retention_days ?? 90));
$deleted    = SUFI\System\Database\prune_inbox($db_handler, $days);

// Uploaded images that no post references and that are older than a day
// (a day of slack so a compose in progress is never cleaned up).
$orphans = 0;
foreach (glob($root . '/sys/uploads/*') ?: [] as $file) {
    if (!is_file($file) || filemtime($file) > time() - 86400) { continue; }

    $name = basename($file);
    $used = (int) $db_handler->query(
        "SELECT COUNT(*) FROM Post WHERE data LIKE " . $db_handler->quote('%' . $name . '%')
    )->fetchColumn();

    if ($used === 0 && @unlink($file)) { $orphans++; }
}

echo date('c') . " pruned inbox items older than {$days}d: {$deleted}, orphaned uploads: {$orphans}\n";
exit(0);
