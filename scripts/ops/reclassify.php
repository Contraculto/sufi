<?php
// Recompute InboxItem scope (inbox vs timeline) and parent_uri for rows
// stored before the split or under older classification rules. Nothing is
// deleted: rows that no longer qualify stay in the timeline, because they
// were already received and may still be useful to read.
//
//   php scripts/ops/reclassify.php            # apply
//   php scripts/ops/reclassify.php --dry-run  # report only
//
// Safe to re-run; run it after upgrading to the inbox/timeline split.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);

require $root . '/sys/config.php';
require $root . '/sys/database.php';

$db  = SUFI\System\Database\connect_database();
$dry = in_array('--dry-run', $argv ?? [], true) || in_array('-n', $argv ?? [], true);

$counts = ['inbox' => 0, 'timeline' => 0, 'drop' => 0];
$rows   = SUFI\System\Database\get($db, "SELECT * FROM InboxItem ORDER BY id");

foreach ($rows as $row) {
    $data = json_decode((string) $row['data'], true) ?: [];

    $parent = SUFI\System\Database\inbox_parent_uri($data);
    [$scope] = SUFI\System\Database\inbox_classify($db, $data, (string) $base_url);

    // History is never dropped by a reclassification.
    if ($scope === 'drop') { $scope = 'timeline'; }

    $counts[$scope]++;

    if ($scope === (string) ($row['scope'] ?? '') && $parent === (string) ($row['parent_uri'] ?? '')) {
        continue;
    }

    if (!$dry) {
        SUFI\System\Database\run($db,
            "UPDATE InboxItem SET scope = ?, parent_uri = ? WHERE id = ?",
            [$scope, $parent, (int) $row['id']]
        );
    }
}

// Out-of-order delivery: a reply stored before the directed post it answers
// belongs to that thread too. One UPDATE per pass, repeated until stable.
$passes = 0;
if (!$dry) {
    do {
        $promoted = $db->exec(
            "UPDATE InboxItem SET scope = 'inbox'
             WHERE scope <> 'inbox'
               AND parent_uri <> ''
               AND parent_uri IN (SELECT object_uri FROM InboxItem
                                  WHERE scope = 'inbox' AND object_uri IS NOT NULL AND object_uri <> '')"
        );
        $passes++;
    } while ($promoted > 0 && $passes < 20);
}

$final = ['inbox' => 0, 'timeline' => 0];
foreach (SUFI\System\Database\get($db, "SELECT scope, COUNT(*) AS n FROM InboxItem GROUP BY scope") as $row) {
    $final[(string) $row['scope']] = (int) $row['n'];
}

printf("inbox items: %d total\n", count($rows));
printf("  classified inbox:    %d\n", $counts['inbox']);
printf("  classified timeline: %d\n", $counts['timeline']);
if ($counts['drop'] > 0) {
    printf("  kept as timeline instead of dropping: %d\n", $counts['drop']);
}
printf("  thread promotions:   %d pass(es)\n", $passes);
printf("after: %d inbox, %d timeline%s\n",
    $final['inbox'] ?? 0, $final['timeline'] ?? 0, $dry ? '  (dry run, nothing written)' : '');
