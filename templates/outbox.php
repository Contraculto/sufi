<?php
namespace SUFI\Templates;

function outbox($user_data) {
    global $base_url, $db_handler, $outbox_page_size;

    $limit = max(1, (int) ($outbox_page_size ?: 20));
    $page  = isset($_GET['page']) && !in_array($_GET['page'], ['false', '0', ''], true);
    $max   = isset($_GET['max_id']) ? (int) $_GET['max_id'] : null;
    $base  = "{$base_url}ap/outbox";

    if (!$page) {
        $total = \SUFI\System\Database\count_posts($db_handler);
        $data  = [
            "@context"   => "https://www.w3.org/ns/activitystreams",
            "id"         => $base,
            "type"       => "OrderedCollection",
            "totalItems" => $total,
            "first"      => "{$base}?page=true",
        ];
        if ($total > 0) {
            $data["last"] = "{$base}?page=true&max_id=" . \SUFI\System\Database\oldest_post_id($db_handler);
        }
        return json_encode($data);
    }

    $rows = \SUFI\System\Database\get_posts_page($db_handler, $limit + 1, $max);
    $more = count($rows) > $limit;
    if ($more) { array_pop($rows); }

    $items = array_map(fn($p) => json_decode($p['data'], true), $rows);

    $data = [
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => $base . '?page=true' . ($max ? "&max_id={$max}" : ''),
        "type"         => "OrderedCollectionPage",
        "partOf"       => $base,
        "orderedItems" => $items,
    ];

    if ($more && $rows) {
        $last_id = (int) $rows[count($rows) - 1]['id'];
        $data["next"] = "{$base}?page=true&max_id=" . ($last_id - 1);
    }

    return json_encode($data);
}
