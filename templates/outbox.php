<?php
namespace SUFI\Templates;

function outbox($user_data) {
    global $server, $db_handler;

    $posts = \SUFI\System\Database\get_posts($db_handler);
    $items = array_map(fn($p) => json_decode($p['data'], true), $posts);

    return json_encode([
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => "https://{$server}/ap/outbox",
        "type"         => "OrderedCollection",
        "totalItems"   => count($items),
        "orderedItems" => $items,
    ]);
}
