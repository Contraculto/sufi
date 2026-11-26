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

$rows = SUFI\System\Database\get($db, "SELECT * FROM InboxItem ORDER BY id");

// Decide everything in memory first, oldest first: a row's scope depends on
// the scope of its parent, and the column still holds old values while we
// work. A parent stored later (out-of-order delivery) is unknown at this
// point and lands in the timeline; the promotion pass below fixes it.
$decided    = [];  // object_uri => 'inbox'|'timeline'
$parents    = [];  // id => parent_uri
$new_scope  = [];  // id => scope
$parent_of  = fn(string $uri): ?string => $decided[$uri] ?? null;

foreach ($rows as $row) {
    $data = json_decode((string) $row['data'], true) ?: [];

    $parent = SUFI\System\Database\inbox_parent_uri($data);
    [$scope] = SUFI\System\Database\inbox_classify($db, $data, (string) $base_url, $parent_of);

    // History is never dropped by a reclassification.
    if ($scope === 'drop') { $scope = 'timeline'; }

    $parents[(int) $row['id']]   = $parent;
    $new_scope[(int) $row['id']] = $scope;

    $uri = (string) ($row['object_uri'] ?? '');
    if ($uri !== '') { $decided[$uri] = $scope; }
}

// Out-of-order delivery: a reply stored before the directed post it answers
// belongs to that thread too. Promote in memory until nothing changes.
$passes = 0;
do {
    $changed = 0;
    foreach ($rows as $row) {
        $id     = (int) $row['id'];
        $parent = $parents[$id];
        if ($new_scope[$id] === 'inbox' || $parent === '') { continue; }
        if (($decided[$parent] ?? null) !== 'inbox') { continue; }

        $new_scope[$id] = 'inbox';
        $uri = (string) ($row['object_uri'] ?? '');
        if ($uri !== '') { $decided[$uri] = 'inbox'; }
        $changed++;
    }
    $passes++;
} while ($changed > 0 && $passes < 20);

$counts = ['inbox' => 0, 'timeline' => 0];
$moved  = 0;

foreach ($rows as $row) {
    $id     = (int) $row['id'];
    $scope  = $new_scope[$id];
    $parent = $parents[$id];
    $counts[$scope]++;

    if ($scope === (string) ($row['scope'] ?? '') && $parent === (string) ($row['parent_uri'] ?? '')) {
        continue;
    }
    $moved++;

    if (!$dry) {
        SUFI\System\Database\run($db,
            "UPDATE InboxItem SET scope = ?, parent_uri = ? WHERE id = ?",
            [$scope, $parent, $id]
        );
    }
}

printf("inbox items: %d total\n", count($rows));
printf("  inbox:      %d\n", $counts['inbox']);
printf("  timeline:   %d\n", $counts['timeline']);
printf("  promotions: %d pass(es)\n", $passes);
printf("  changed:    %d row(s)%s\n", $moved, $dry ? '  (dry run, nothing written)' : '');
