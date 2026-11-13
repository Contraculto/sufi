<?php
// SUFI test suite. Runs against the sandbox started by scripts/test.sh.
// Plain PHP: no PHPUnit, no composer, no network access beyond localhost.

declare(strict_types=1);

$instance = rtrim((string) getenv('SUFI_URL'), '/');
$prefix   = rtrim((string) getenv('SUFI_PREFIX_URL'), '/');
$remote   = rtrim((string) getenv('SUFI_REMOTE_URL'), '/');
$db_path  = (string) getenv('SUFI_DB');
$pdb_path = (string) getenv('SUFI_PREFIX_DB');
$key      = (string) getenv('SUFI_REMOTE_KEY');
$rlog     = (string) getenv('SUFI_REMOTE_LOG');

$count    = 0;
$failures = 0;

// ── helpers ───────────────────────────────────────────────────────────────────

function section(string $name): void {
    echo "\n\033[1m{$name}\033[0m\n";
}

function check(string $label, $expected, $actual): void {
    global $count, $failures;
    $count++;
    if ($expected === $actual) {
        echo "  \033[32mok\033[0m   {$label}\n";
        return;
    }
    $failures++;
    echo "  \033[31mFAIL\033[0m {$label}\n";
    echo "       expected: " . var_export($expected, true) . "\n";
    echo "       actual:   " . var_export($actual, true) . "\n";
}

function expect(string $label, int $status, array $response): array {
    check("{$label} → {$status}", $status, $response[0]);
    return $response;
}

/** @return array{0:int,1:array<string,array<int,string>>,2:string} */
function http(string $method, string $url, ?string $body = null, array $headers = [], ?string &$jar = null): array {
    $headers[] = 'Connection: close';
    if ($jar !== null && $jar !== '') {
        $headers[] = 'Cookie: ' . $jar;
    }
    $options = ['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $headers),
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 10,
    ]];
    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    $response = @file_get_contents($url, false, stream_context_create($options));
    $raw      = $http_response_header ?? [];

    $status = 0;
    $out    = [];
    foreach ($raw as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
            $out    = [];
            continue;
        }
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $out[strtolower(trim($name))][] = trim($value);
        }
    }

    if ($jar !== null) {
        foreach ($out['set-cookie'] ?? [] as $cookie) {
            $pair = trim(explode(';', $cookie)[0]);
            if (str_contains($pair, '=')) {
                $jar = $pair;
            }
        }
    }

    return [$status, $out, (string) $response];
}

function post(string $url, array $fields, ?string &$jar = null): array {
    return http('POST', $url, http_build_query($fields),
        ['Content-Type: application/x-www-form-urlencoded'], $jar);
}

function sign_post(string $url, array $payload, string $key_path, string $key_id, ?string $send_body = null): array {
    $json   = json_encode($payload);
    $parts  = parse_url($url);
    $host   = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $date   = gmdate('D, d M Y H:i:s \G\M\T');
    $digest = base64_encode(hash('sha256', $json, true));

    $string = "(request-target): post {$path}\nhost: {$host}\ndate: {$date}\ndigest: SHA-256={$digest}";
    openssl_sign($string, $signature, openssl_get_privatekey(file_get_contents($key_path)), OPENSSL_ALGO_SHA256);

    return http('POST', $url, $send_body ?? $json, [
        'Host: ' . $host,
        'Date: ' . $date,
        'Digest: SHA-256=' . $digest,
        'Content-Type: application/activity+json',
        'Signature: keyId="' . $key_id . '",algorithm="rsa-sha256",'
            . 'headers="(request-target) host date digest",signature="' . base64_encode($signature) . '"',
    ]);
}

function json_body(array $response): array {
    return json_decode($response[2], true) ?: [];
}

function db(string $path, string $sql, array $params = []): array {
    $stmt = (new PDO('sqlite:' . $path))->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function token(string $html): ?string {
    return preg_match('/const CSRF_TOKEN = "([^"]+)"/', $html, $m) ? $m[1] : null;
}

// ── discovery ─────────────────────────────────────────────────────────────────

section('Discovery');

$r = expect('webfinger', 200, http('GET', "{$instance}/.well-known/webfinger?resource=acct:alice@x"));
check('webfinger is JRD', true, str_contains(implode(',', $r[1]['content-type'] ?? []), 'application/jrd+json'));
$jrd = json_body($r);
check('webfinger subject', 'acct:alice@' . parse_url($instance, PHP_URL_HOST) . ':' . parse_url($instance, PHP_URL_PORT), $jrd['subject'] ?? null);
$self = array_values(array_filter($jrd['links'] ?? [], fn($l) => ($l['rel'] ?? '') === 'self'))[0]['href'] ?? null;
check('webfinger self points at actor', "{$instance}/ap/actor", $self);

$r = expect('host-meta', 200, http('GET', "{$instance}/.well-known/host-meta"));
check('host-meta advertises lrdd', true, str_contains($r[2], 'lrdd'));

$r = expect('actor', 200, http('GET', "{$instance}/ap/actor"));
check('actor is activity+json', true, str_contains(implode(',', $r[1]['content-type'] ?? []), 'application/activity+json'));
$actor = json_body($r);
check('actor id', "{$instance}/ap/actor", $actor['id'] ?? null);
check('actor username', 'alice', $actor['preferredUsername'] ?? null);
check('actor inbox', "{$instance}/ap/inbox", $actor['inbox'] ?? null);
check('actor has public key', true, str_contains((string) ($actor['publicKey']['publicKeyPem'] ?? ''), 'BEGIN PUBLIC KEY'));
check('actor key id', "{$instance}/ap/actor#main-key", $actor['publicKey']['id'] ?? null);
check('actor auto-created from config', 'Alice', $actor['name'] ?? null);
check('one actor row', 1, count(db($db_path, 'SELECT * FROM Actor')));

// ── routing ───────────────────────────────────────────────────────────────────

section('Routing');

$r = expect('profile page', 200, http('GET', "{$instance}/"));
check('profile shows handle', true, str_contains($r[2], 'alice@' . parse_url($instance, PHP_URL_HOST) . ':' . parse_url($instance, PHP_URL_PORT)));
expect('@handle page', 200, http('GET', "{$instance}/@alice"));
expect('unknown path', 404, http('GET', "{$instance}/nope"));

// ── API auth ──────────────────────────────────────────────────────────────────

section('API authentication');

expect('create post without password', 401, post("{$instance}/ap/post", ['content' => 'x']));
expect('create post with wrong password', 401, post("{$instance}/ap/post", ['content' => 'x', 'password' => 'nope']));
expect('inbox listing without auth', 401, http('GET', "{$instance}/ap/inbox"));
expect('follow action without password', 401, post("{$instance}/ap/action", ['user' => '@bob@example.com', 'action' => 'Follow']));

// ── posts ─────────────────────────────────────────────────────────────────────

section('Posts (password API)');

$r = expect('create post', 200, post("{$instance}/ap/post", ['content' => 'first post', 'password' => 'testpass']));
$create = json_body($r);
check('create is a Create activity', 'Create', $create['type'] ?? null);
check('note content', 'first post', $create['object']['content'] ?? null);
check('note attributed to actor', "{$instance}/ap/actor", $create['object']['attributedTo'] ?? null);
$guid = basename((string) ($create['object']['id'] ?? ''));
check('note id is under /ap/post/', true, str_starts_with((string) ($create['object']['id'] ?? ''), "{$instance}/ap/post/"));

$r = expect('fetch post', 200, http('GET', "{$instance}/ap/post/{$guid}"));
check('post URL serves the Note', 'Note', json_body($r)['type'] ?? null);
check('post URL id is the Note id', $create['object']['id'], json_body($r)['id'] ?? null);

$r = expect('outbox', 200, http('GET', "{$instance}/ap/outbox"));
check('outbox has one item', 1, json_body($r)['totalItems'] ?? null);

$r = expect('update post', 200, post("{$instance}/ap/post/{$guid}", ['action' => 'update', 'content' => 'edited', 'password' => 'testpass']));
check('update activity type', 'Update', json_body($r)['type'] ?? null);
$r = expect('fetch updated post', 200, http('GET', "{$instance}/ap/post/{$guid}"));
check('updated content', 'edited', json_body($r)['content'] ?? null);

// ── panel ─────────────────────────────────────────────────────────────────────

section('Panel');

expect('panel canonical redirect', 302, http('GET', "{$instance}/sufi"));
$r = expect('login form', 200, http('GET', "{$instance}/sufi/"));
check('login form has password field', true, str_contains($r[2], 'name="password"'));

$r = expect('wrong password redirects', 302, post("{$instance}/sufi/", ['password' => 'wrong']));
check('wrong password flags error', true, str_contains(implode(',', $r[1]['location'] ?? []), 'error=1'));

$jar = '';
$r = expect('login', 302, post("{$instance}/sufi/", ['password' => 'testpass'], $jar));
check('login redirects to panel', "{$instance}/sufi/", $r[1]['location'][0] ?? null);
check('session cookie set', true, str_starts_with($jar, 'PHPSESSID='));
check('cookie is httponly', true, str_contains(implode(',', $r[1]['set-cookie'] ?? []), 'HttpOnly'));

$r = expect('panel with session', 200, http('GET', "{$instance}/sufi/", null, [], $jar));
check('panel rendered', true, str_contains($r[2], 'id="compose"'));
check('panel BASE is instance root', true, str_contains($r[2], 'const BASE = ' . json_encode($instance)));
$csrf = token($r[2]);
check('panel exposes csrf token', true, $csrf !== null);

expect('session post without csrf', 403, post("{$instance}/ap/post", ['content' => 'nope'], $jar));
$r = expect('session post with csrf', 200, post("{$instance}/ap/post", ['content' => 'from panel', 'csrf_token' => $csrf], $jar));
$second = json_body($r);
check('panel post created', 'from panel', $second['object']['content'] ?? null);

expect('delete post', 200, post("{$instance}/ap/post/{$guid}", ['action' => 'delete', 'password' => 'testpass']));
expect('deleted post is gone', 404, http('GET', "{$instance}/ap/post/{$guid}"));

expect('logout', 302, http('GET', "{$instance}/sufi/?logout=1", null, [], $jar));
$r = expect('panel after logout', 200, http('GET', "{$instance}/sufi/", null, [], $jar));
check('login form again', true, str_contains($r[2], 'name="password"'));

// ── inbox: forged activities ──────────────────────────────────────────────────

section('Inbox: unsigned and forged activities');

$inbox  = "{$instance}/ap/inbox";
$actorU = "{$remote}/u/bob";
$keyId  = "{$actorU}#main-key";
$follow = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#follows/1",
    'type'     => 'Follow',
    'actor'    => $actorU,
    'object'   => "{$instance}/ap/actor",
];

expect('unsigned activity', 401, http('POST', $inbox, json_encode($follow), ['Content-Type: application/activity+json']));
expect('garbage signature', 401, http('POST', $inbox, json_encode($follow), [
    'Content-Type: application/activity+json',
    'Signature: keyId="x",algorithm="rsa-sha256",headers="(request-target)",signature="AAAA"',
]));

// Signed over the original body, but a different body is sent: digest must fail.
expect('tampered body', 401, sign_post($inbox, $follow, $key, $keyId,
    json_encode(['type' => 'Like', 'actor' => $actorU, 'object' => "{$instance}/ap/actor"])));

// Signature made with a key the actor does not publish.
$evil_key = dirname($key) . '/evil.pem';
$evil_res = openssl_pkey_new(['private_key_bits' => 2048]);
openssl_pkey_export($evil_res, $evil_pem);
file_put_contents($evil_key, $evil_pem);
expect('wrong signing key', 401, sign_post($inbox, $follow, $evil_key, $keyId));

check('nothing stored from forged requests', 0, count(db($db_path, 'SELECT * FROM Follower')));

// ── inbox: valid activities ───────────────────────────────────────────────────

section('Inbox: signed Follow, Like, Create, Undo');

expect('signed Follow', 202, sign_post($inbox, $follow, $key, $keyId));
check('follower stored', 1, count(db($db_path, 'SELECT * FROM Follower')));
check('Accept delivered to follower', true, str_contains((string) file_get_contents($rlog), '"type":"Accept"'));

$note_uri = "{$instance}/ap/post/abc123";
$like = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#likes/1",
    'type'     => 'Like',
    'actor'    => $actorU,
    'object'   => $second['object']['id'],
];
expect('signed Like', 202, sign_post($inbox, $like, $key, $keyId));
$r = expect('likes collection', 200, http('GET', "{$instance}/ap/likes"));
check('like recorded', true, in_array($second['object']['id'], json_body($r)['orderedItems'] ?? [], true));

$create_remote = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#creates/1",
    'type'     => 'Create',
    'actor'    => $actorU,
    'object'   => ['id' => $note_uri, 'type' => 'Note', 'content' => 'hello from bob'],
];
expect('signed Create', 202, sign_post($inbox, $create_remote, $key, $keyId));
check('inbox item stored', 1, count(db($db_path, "SELECT * FROM InboxItem WHERE type = 'Create'")));

expect('signed Undo Like', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#undos/1",
    'type'     => 'Undo',
    'actor'    => $actorU,
    'object'   => $like,
], $key, $keyId));
$r = expect('likes after undo', 200, http('GET', "{$instance}/ap/likes"));
check('like removed', 0, json_body($r)['totalItems'] ?? null);

// Delivery to followers on a new post.
$r = expect('post to followers', 200, post("{$instance}/ap/post", ['content' => 'delivery-marker', 'password' => 'testpass']));
check('new post delivered to follower inbox', true, str_contains((string) file_get_contents($rlog), '"content":"delivery-marker"'));

expect('signed Undo Follow', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#undos/2",
    'type'     => 'Undo',
    'actor'    => $actorU,
    'object'   => $follow,
], $key, $keyId));
check('follower removed', 0, count(db($db_path, 'SELECT * FROM Follower')));

// ── subdirectory deployment ───────────────────────────────────────────────────

section('Subdirectory deployment (/sufi prefix)');

$r = expect('prefixed actor', 200, http('GET', "{$prefix}/sufi/ap/actor"));
$actor = json_body($r);
check('prefixed actor id', "{$prefix}/sufi/ap/actor", $actor['id'] ?? null);
check('prefixed inbox', "{$prefix}/sufi/ap/inbox", $actor['inbox'] ?? null);
check('prefixed outbox link', "{$prefix}/sufi/ap/outbox", $actor['outbox'] ?? null);

$r = expect('prefixed webfinger', 200, http('GET', "{$prefix}/sufi/.well-known/webfinger?resource=acct:alice@x"));
check('prefixed webfinger aliases', ["{$prefix}/sufi/ap/actor"], json_body($r)['aliases'] ?? null);

expect('prefix boundary', 404, http('GET', "{$prefix}/sufi2/ap/actor"));

$r = expect('prefixed panel', 200, http('GET', "{$prefix}/sufi/sufi/"));
check('prefixed login form', true, str_contains($r[2], 'name="password"'));

$jar = '';
$r = expect('prefixed login', 302, post("{$prefix}/sufi/sufi/", ['password' => 'testpass'], $jar));
check('prefixed login redirect', "{$prefix}/sufi/sufi/", $r[1]['location'][0] ?? null);

$r = expect('prefixed panel with session', 200, http('GET', "{$prefix}/sufi/sufi/", null, [], $jar));
check('prefixed panel BASE', true, str_contains($r[2], 'const BASE = ' . json_encode($prefix . '/sufi')));
$csrf = token($r[2]);
$r = expect('prefixed post', 200, post("{$prefix}/sufi/ap/post", ['content' => 'prefixed', 'csrf_token' => $csrf], $jar));
check('prefixed post id', true, str_starts_with((string) (json_body($r)['object']['id'] ?? ''), "{$prefix}/sufi/ap/post/"));
check('prefixed post stored', 1, count(db($pdb_path, 'SELECT * FROM Post')));

// Rotating sys/private.pem must update the served public key by itself.
$new_res = openssl_pkey_new(['private_key_bits' => 2048]);
openssl_pkey_export($new_res, $new_priv);
file_put_contents((string) getenv('SUFI_PREFIX_KEY'), $new_priv);
$r = expect('actor after key rotation', 200, http('GET', "{$prefix}/sufi/ap/actor"));
check('rotated key is served', trim(openssl_pkey_get_details($new_res)['key']), trim(json_body($r)['publicKey']['publicKeyPem'] ?? ''));
check('actor not duplicated', 1, count(db($pdb_path, 'SELECT * FROM Actor')));

// ── summary ───────────────────────────────────────────────────────────────────

echo "\n";
if ($failures === 0) {
    echo "\033[32m{$count} checks passed\033[0m\n";
    exit(0);
}
echo "\033[31m{$failures} of {$count} checks failed\033[0m\n";
exit(1);
