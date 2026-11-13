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

echo date('c') . " pruned inbox items older than {$days}d: {$deleted}\n";
exit(0);
