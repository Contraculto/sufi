<?php
namespace SUFI\Templates;

function likes($user_data) {
    global $server, $db_handler;

    $rows  = \SUFI\System\Database\get_likes($db_handler);
    $items = array_column($rows, 'object_uri');

    return json_encode([
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => "https://{$server}/ap/likes",
        "type"         => "OrderedCollection",
        "totalItems"   => count($items),
        "orderedItems" => $items,
    ]);
}
