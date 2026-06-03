<?php
namespace SUFI\Templates;

function webfinger($user_data) {
    global $server, $username;
    $base = "https://{$server}/";

    $data = [
        "subject" => "acct:{$username}@{$server}",
        "aliases" => ["{$base}ap/actor"],
        "links"   => [
            [
                "rel"  => "http://webfinger.net/rel/profile-page",
                "type" => "text/html",
                "href" => $base,
            ],
            [
                "rel"  => "self",
                "type" => "application/activity+json",
                "href" => "{$base}ap/actor",
            ],
            [
                "rel"      => "http://ostatus.org/schema/1.0/subscribe",
                "template" => "{$base}ap/follow?uri={uri}",
            ],
        ],
    ];

    return json_encode($data);
}
