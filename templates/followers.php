<?php
namespace SUFI\Templates;

function followers($user_data) {
    global $base_url, $db_handler;

    $rows  = \SUFI\System\Database\get_followers($db_handler);
    $items = array_column($rows, 'actor_uri');

    return json_encode([
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => "{$base_url}ap/followers",
        "type"         => "OrderedCollection",
        "totalItems"   => count($items),
        "orderedItems" => $items,
    ]);
}
