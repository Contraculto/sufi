<?php
// A throwaway "other server" for the test suite. It serves one actor (bob) and
// records every activity delivered to its inbox. Started by scripts/test.sh:
//
//   SUFI_REMOTE_KEY=/path/to/bob.pem SUFI_REMOTE_LOG=/path/to/inbox.log \
//     php -S 127.0.0.1:PORT tests/remote.php

$key_path = getenv('SUFI_REMOTE_KEY');
$log_path = getenv('SUFI_REMOTE_LOG');
$host     = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$uri      = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

header('Content-Type: application/activity+json');

if ($uri === '/u/bob') {
    $id = "http://{$host}/u/bob";
    $details = openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents($key_path)));
    echo json_encode([
        '@context'          => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
        'id'                => $id,
        'type'              => 'Person',
        'preferredUsername' => 'bob',
        'name'              => 'Bob',
        'inbox'             => "http://{$host}/inbox",
        'endpoints'         => ['sharedInbox' => "http://{$host}/inbox"],
        'publicKey'         => [
            'id'           => "{$id}#main-key",
            'owner'        => $id,
            'publicKeyPem' => $details['key'],
        ],
    ]);
    exit;
}

if ($uri === '/inbox' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    file_put_contents($log_path, file_get_contents('php://input') . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(202);
    exit;
}

http_response_code(404);
echo '{"error":"not found"}';
