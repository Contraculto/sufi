<?php
// Fill in actor handles on inbox items stored before handles were recorded.
// Fetches each distinct actor once (respecting the normal fetch guards and
// cache) and skips anything that cannot be resolved, so it is safe to re-run.
//
//   php scripts/ops/backfill.php        # up to 200 actors
//   php scripts/ops/backfill.php 1000   # more per run

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);

require $root . '/sys/config.php';
require $root . '/sys/database.php';
require $root . '/sys/delivery.php';

$db_handler = SUFI\System\Database\connect_database();
$limit      = max(1, (int) ($argv[1] ?? 200));

$rows = SUFI\System\Database\get(
    $db_handler,
    "SELECT DISTINCT actor_uri FROM InboxItem
     WHERE actor_handle = '' AND actor_uri LIKE 'http%' LIMIT ?",
    [$limit]
);

$updated = 0;
$skipped = 0;

foreach ($rows as $row) {
    $uri = (string) $row['actor_uri'];
    if ($uri === '') { continue; }

    $doc = SUFI\getDataFromURl($uri);
    if (!is_array($doc) || empty($doc['preferredUsername'])) {
        // Account gone or unreachable: fall back to @id@host from the URI.
        $skipped++;
    }

    SUFI\System\Database\run(
        $db_handler,
        "UPDATE InboxItem SET actor_handle = ? WHERE actor_uri = ?",
        [SUFI\actor_handle($uri, is_array($doc) ? $doc : []), $uri]
    );
    $updated++;
    usleep(250000);
}

$remaining = (int) $db_handler
    ->query("SELECT COUNT(DISTINCT actor_uri) FROM InboxItem WHERE actor_handle = '' AND actor_uri LIKE 'http%'")
    ->fetchColumn();

echo date('c') . " backfilled={$updated} skipped={$skipped} remaining={$remaining}\n";
exit(0);
