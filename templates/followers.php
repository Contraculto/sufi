<?php
namespace SUFI\Templates;

function followers($user_data) {
    global $server, $db_handler;

    $rows  = \SUFI\System\Database\get_followers($db_handler);
    $items = array_column($rows, 'actor_uri');

    return json_encode([
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => "https://{$server}/ap/followers",
        "type"         => "OrderedCollection",
        "totalItems"   => count($items),
        "orderedItems" => $items,
    ]);
}
