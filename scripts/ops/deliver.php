<?php
// SUFI delivery worker. Drains the Delivery queue and retries with backoff.
// Run it from cron (see deploy/ or scripts/deploy.sh):
//
//   * * * * * www-data php /path/to/sufi/scripts/ops/deliver.php
//
// Safe to run as often as you like: rows are claimed by next_try and deleted
// once a 2xx response comes back.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);

require $root . '/sys/config.php';
require $root . '/sys/database.php';
require $root . '/sys/delivery.php';

$db_handler = SUFI\System\Database\connect_database();
$sent       = SUFI\deliver_pending(100);
$left       = SUFI\System\Database\count_deliveries($db_handler);

echo date('c') . " delivered={$sent} pending={$left}\n";
exit(0);
