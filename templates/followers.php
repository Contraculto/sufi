<?php
namespace SUFI\Templates;

function followers($user_data) {
    global $base_url, $db_handler, $collection_page_size;

    $limit = max(1, (int) ($collection_page_size ?? 40));
    $page  = isset($_GET['page']) && !in_array($_GET['page'], ['false', '0', ''], true);
    $max   = isset($_GET['max_id']) ? (int) $_GET['max_id'] : null;
    $base  = "{$base_url}ap/followers";

    if (!$page) {
        $total = \SUFI\System\Database\count_followers($db_handler);
        $data  = [
            "@context"   => "https://www.w3.org/ns/activitystreams",
            "id"         => $base,
            "type"       => "OrderedCollection",
            "totalItems" => $total,
            "first"      => "{$base}?page=true",
        ];
        if ($total > 0) {
            $data["last"] = "{$base}?page=true&max_id=" . \SUFI\System\Database\oldest_follower_id($db_handler);
        }
        return json_encode($data);
    }

    $rows = \SUFI\System\Database\get_followers_page($db_handler, $limit + 1, $max);
    $more = count($rows) > $limit;
    if ($more) { array_pop($rows); }

    $data = [
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => $base . '?page=true' . ($max ? "&max_id={$max}" : ''),
        "type"         => "OrderedCollectionPage",
        "partOf"       => $base,
        "orderedItems" => array_column($rows, 'actor_uri'),
    ];

    if ($more && $rows) {
        $last_id = (int) $rows[count($rows) - 1]['id'];
        $data["next"] = "{$base}?page=true&max_id=" . ($last_id - 1);
    }

    return json_encode($data);
}
