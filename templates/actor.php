<?php
namespace SUFI\Templates;

function actor($user_data) {
    global $base_url, $username;
    $base = $base_url;

    $data = [
        "@context" => [
            "https://www.w3.org/ns/activitystreams",
            "https://w3id.org/security/v1",
        ],
        "id"                => "{$base}ap/actor",
        "type"              => $user_data["type"] ?? "Person",
        "preferredUsername" => $username,
        "name"              => $user_data["name"]    ?? $username,
        "summary"           => $user_data["summary"] ?? "",
        "url"               => $base,
        "inbox"             => "{$base}ap/inbox",
        "outbox"            => "{$base}ap/outbox",
        "followers"         => "{$base}ap/followers",
        "following"         => "{$base}ap/following",
        "endpoints"         => [
            "sharedInbox" => "{$base}ap/inbox",
        ],
        "publicKey"         => [
            "id"           => "{$base}ap/actor#main-key",
            "owner"        => "{$base}ap/actor",
            "publicKeyPem" => $user_data["public_key"] ?? "",
        ],
    ];

    if (!empty($user_data["icon"])) {
        $data["icon"] = ["type" => "Image", "url" => $user_data["icon"]];
    }

    return json_encode($data);
}
