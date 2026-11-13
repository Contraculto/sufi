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

function rfc9421_post(string $url, array $payload, string $key_path, string $key_id): array {
    $json   = json_encode($payload);
    $parts  = parse_url($url);
    $host   = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $path   = $parts['path'] ?? '/';
    $date   = gmdate('D, d M Y H:i:s \G\M\T');
    $digest = 'sha-256=:' . base64_encode(hash('sha256', $json, true)) . ':';

    $components = ['@method', '@path', 'host', 'date', 'content-digest'];
    $params     = '("' . implode('" "', $components) . '");created=' . time()
        . ';keyid="' . $key_id . '";alg="rsa-v1_5-sha256"';

    $base = implode("\n", [
        '"@method": POST',
        '"@path": ' . $path,
        '"host": ' . $host,
        '"date": ' . $date,
        '"content-digest": ' . $digest,
        '"@signature-params": ' . $params,
    ]);
    openssl_sign($base, $signature, openssl_get_privatekey(file_get_contents($key_path)), OPENSSL_ALGO_SHA256);

    return http('POST', $url, $json, [
        'Host: ' . $host,
        'Date: ' . $date,
        'Content-Digest: ' . $digest,
        'Content-Type: application/activity+json',
        'Signature-Input: sig1=' . $params,
        'Signature: sig1=:' . base64_encode($signature) . ':',
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

function multipart(array $fields, string $filename, string $type, string $data): array {
    $boundary = '----sufi' . bin2hex(random_bytes(8));
    $body     = '';
    foreach ($fields as $key => $value) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$key}\"\r\n\r\n{$value}\r\n";
    }
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
        . "Content-Type: {$type}\r\n\r\n{$data}\r\n--{$boundary}--\r\n";

    return [$body, 'Content-Type: multipart/form-data; boundary=' . $boundary];
}

function form_token(string $html): ?string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : null;
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

$r = expect('nodeinfo discovery', 200, http('GET', "{$instance}/.well-known/nodeinfo"));
check('nodeinfo link', "{$instance}/ap/nodeinfo", json_body($r)['links'][0]['href'] ?? null);

$r = expect('nodeinfo document', 200, http('GET', "{$instance}/ap/nodeinfo"));
$nodeinfo = json_body($r);
check('nodeinfo version', '2.1', $nodeinfo['version'] ?? null);
check('nodeinfo software', 'sufi', $nodeinfo['software']['name'] ?? null);
check('nodeinfo protocols', ['activitypub'], $nodeinfo['protocols'] ?? null);
check('nodeinfo closed registrations', false, $nodeinfo['openRegistrations'] ?? null);

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
check('note content is html', '<p>first post</p>', $create['object']['content'] ?? null);
check('note attributed to actor', "{$instance}/ap/actor", $create['object']['attributedTo'] ?? null);
$guid = basename((string) ($create['object']['id'] ?? ''));
check('note id is under /ap/post/', true, str_starts_with((string) ($create['object']['id'] ?? ''), "{$instance}/ap/post/"));

$r = expect('fetch post', 200, http('GET', "{$instance}/ap/post/{$guid}"));
check('post URL serves the Note', 'Note', json_body($r)['type'] ?? null);
check('post URL id is the Note id', $create['object']['id'], json_body($r)['id'] ?? null);

$r = expect('formatting post', 200, post("{$instance}/ap/post", [
    'content' => "hello <script>alert(1)</script>\n\nsecond https://example.com/x",
    'password' => 'testpass',
]));
$html = json_body($r)['object']['content'] ?? '';
check('content is wrapped in paragraphs', true, str_starts_with($html, '<p>'));
check('content escapes html', true, str_contains($html, '&lt;script&gt;'));
check('content has no raw script', false, str_contains($html, '<script>'));
check('content linkifies urls', true, str_contains($html, '<a href="https://example.com/x"'));
check('content splits paragraphs', true, str_contains($html, '</p><p>second'));

$r = post("{$instance}/ap/post", ['content' => str_repeat('a', 501), 'password' => 'testpass']);
check('overlong post rejected', 400, $r[0]);

$r = expect('outbox', 200, http('GET', "{$instance}/ap/outbox"));
check('outbox has two items', 2, json_body($r)['totalItems'] ?? null);

section('Mentions and hashtags');

$remote_host = parse_url($remote, PHP_URL_HOST) . ':' . parse_url($remote, PHP_URL_PORT);
$local_host  = parse_url($instance, PHP_URL_HOST) . ':' . parse_url($instance, PHP_URL_PORT);

$r = expect('post with mentions and hashtag', 200, post("{$instance}/ap/post", [
    'content'  => "hello @bob@{$remote_host} and @alice@{$local_host} #Hola",
    'password' => 'testpass',
]));
$tagged = json_body($r)['object'];
check('mention is linked', true, str_contains($tagged['content'] ?? '', 'class="mention"'));
check('hashtag is linked', true, str_contains($tagged['content'] ?? '', 'class="hashtag"'));

$by_name = [];
foreach ($tagged['tag'] ?? [] as $tag) { $by_name[$tag['name']] = $tag; }
check('remote mention resolved', "{$remote}/u/bob", $by_name["@bob@{$remote_host}"]['href'] ?? null);
check('self mention resolved', "{$instance}/ap/actor", $by_name["@alice@{$local_host}"]['href'] ?? null);
check('hashtag tag href', "{$instance}/tags/hola", $by_name['#Hola']['href'] ?? null);

$r = expect('tag page', 200, http('GET', "{$instance}/tags/hola"));
check('tag page lists the post', true, str_contains($r[2], 'Hola'));

$r = expect('update post', 200, post("{$instance}/ap/post/{$guid}", ['action' => 'update', 'content' => 'edited', 'password' => 'testpass']));
check('update activity type', 'Update', json_body($r)['type'] ?? null);
$r = expect('fetch updated post', 200, http('GET', "{$instance}/ap/post/{$guid}"));
check('updated content', '<p>edited</p>', json_body($r)['content'] ?? null);

// ── outbox pagination ────────────────────────────────────────────────────────

section('Outbox pagination');

for ($i = 0; $i < 4; $i++) {
    expect("seed post {$i}", 200, post("{$instance}/ap/post", ['content' => "page {$i}", 'password' => 'testpass']));
}

$r = expect('outbox collection', 200, http('GET', "{$instance}/ap/outbox"));
$collection = json_body($r);
$total      = (int) ($collection['totalItems'] ?? 0);
check('collection type', 'OrderedCollection', $collection['type'] ?? null);
check('collection first page', true, str_ends_with((string) ($collection['first'] ?? ''), '?page=true'));
check('collection last page', true, str_contains((string) ($collection['last'] ?? ''), 'max_id='));
check('total matches database', count(db($db_path, 'SELECT * FROM Post')), $total);

$url   = $collection['first'];
$items = 0;
$pages = 0;
$seen  = [];
while ($url !== null && $pages < 20) {
    $page = json_body(expect('outbox page ' . ($pages + 1), 200, http('GET', $url)));
    check('page type', 'OrderedCollectionPage', $page['type'] ?? null);
    check('page partOf', "{$instance}/ap/outbox", $page['partOf'] ?? null);
    foreach ($page['orderedItems'] ?? [] as $item) {
        $seen[$item['object']['id'] ?? $item['id'] ?? ''] = true;
    }
    $items += count($page['orderedItems'] ?? []);
    $url    = $page['next'] ?? null;
    $pages++;
}
check('pages cover every post', $total, $items);
check('no duplicate posts', $total, count($seen));
check('page size respected', (int) ceil($total / 2), $pages);

// ── panel ─────────────────────────────────────────────────────────────────────

section('Panel');

expect('panel canonical redirect', 302, http('GET', "{$instance}/sufi"));
$jar = '';
$r = expect('login form', 200, http('GET', "{$instance}/sufi/", null, [], $jar));
check('login form has password field', true, str_contains($r[2], 'name="password"'));
$login_csrf = form_token($r[2]);
check('login form has csrf token', true, $login_csrf !== null);

$r = expect('wrong password redirects', 302, post("{$instance}/sufi/", ['password' => 'wrong', 'csrf_token' => $login_csrf]));
check('wrong password flags error', true, str_contains(implode(',', $r[1]['location'] ?? []), 'error=1'));

$r = expect('login without csrf redirects', 302, post("{$instance}/sufi/", ['password' => 'testpass']));
check('login without csrf is refused', true, str_contains(implode(',', $r[1]['location'] ?? []), 'error=1'));

$r = expect('login', 302, post("{$instance}/sufi/", ['password' => 'testpass', 'csrf_token' => $login_csrf], $jar));
check('login redirects to panel', "{$instance}/sufi/", $r[1]['location'][0] ?? null);
check('session cookie set', true, str_starts_with($jar, 'PHPSESSID='));
check('cookie is httponly', true, str_contains(implode(',', $r[1]['set-cookie'] ?? []), 'HttpOnly'));

$r = expect('panel with session', 200, http('GET', "{$instance}/sufi/", null, [], $jar));
check('panel rendered', true, str_contains($r[2], 'id="compose"'));
check('panel BASE is instance root', true, str_contains($r[2], 'const BASE = ' . json_encode($instance)));
$csrf = token($r[2]);
check('panel exposes csrf token', true, $csrf !== null);

$r = expect('people endpoint', 200, http('GET', "{$instance}/ap/people", null, [], $jar));
check('no followers yet', [], json_body($r)['followers'] ?? null);
check('not following anyone yet', [], json_body($r)['following'] ?? null);

$r = post("{$instance}/ap/profile", ['name' => 'Alice Updated', 'summary' => 'new bio', 'icon' => '', 'csrf_token' => $csrf], $jar);
check('profile update', 200, $r[0]);
$r = expect('actor after profile update', 200, http('GET', "{$instance}/ap/actor"));
check('actor name updated', 'Alice Updated', json_body($r)['name'] ?? null);
check('actor summary updated', 'new bio', json_body($r)['summary'] ?? null);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
[$body, $type] = multipart([], 'pixel.png', 'image/png', $png);
$r = expect('image upload', 200, http('POST', "{$instance}/ap/upload", $body,
    [$type, 'X-CSRF-Token: ' . $csrf], $jar));
$media = json_body($r);
check('upload returns local url', true, str_starts_with((string) ($media['url'] ?? ''), "{$instance}/ap/media/"));
check('upload media type', 'image/png', $media['mediaType'] ?? null);

[$body, $type] = multipart([], 'notes.txt', 'text/plain', 'not an image');
$r = http('POST', "{$instance}/ap/upload", $body, [$type, 'X-CSRF-Token: ' . $csrf], $jar);
check('non-image upload rejected', 400, $r[0]);

expect('session post without csrf', 403, post("{$instance}/ap/post", ['content' => 'nope'], $jar));
$r = expect('session post with csrf', 200, post("{$instance}/ap/post", [
    'content' => 'from panel',
    'attachment' => $media['url'],
    'attachment_description' => 'a pixel',
    'csrf_token' => $csrf,
], $jar));
$second = json_body($r);
check('panel post created', '<p>from panel</p>', $second['object']['content'] ?? null);
check('post has attachment', $media['url'], $second['object']['attachment'][0]['url'] ?? null);
check('attachment has alt text', 'a pixel', $second['object']['attachment'][0]['name'] ?? null);
check('attachment media type', 'image/png', $second['object']['attachment'][0]['mediaType'] ?? null);

$r = http('GET', $media['url']);
check('media served', 200, $r[0]);
check('media content type', true, str_contains(implode(',', $r[1]['content-type'] ?? []), 'image/png'));
check('media bytes round-trip', $png, $r[2]);

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

// The panel should show a readable handle, not the raw actor URI.
$r = expect('people after follow', 200, http('GET', "{$instance}/ap/people",
    json_encode(['password' => 'testpass']), ['Content-Type: application/json']));
$people = json_body($r);
check('follower handle', '@bob@' . parse_url($remote, PHP_URL_HOST) . ':' . parse_url($remote, PHP_URL_PORT), $people['followers'][0]['handle'] ?? null);
check('follower name', 'Bob', $people['followers'][0]['name'] ?? null);
check('follower profile url', "{$remote}/u/bob", $people['followers'][0]['url'] ?? null);

// Remote profile changes must refresh what we stored.
expect('signed Update actor', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#updates/1",
    'type'     => 'Update',
    'actor'    => $actorU,
    'object'   => [
        'id'                => $actorU,
        'type'              => 'Person',
        'preferredUsername' => 'bob',
        'name'              => 'Bob Renamed',
        'inbox'             => "{$remote}/inbox",
        'endpoints'         => ['sharedInbox' => "{$remote}/inbox"],
    ],
], $key, $keyId));
check('follower data refreshed', true,
    str_contains((string) (db($db_path, 'SELECT data FROM Follower WHERE actor_uri = ?', [$actorU])[0]['data'] ?? ''), 'Bob Renamed'));

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

$hostport = parse_url($remote, PHP_URL_HOST) . ':' . parse_url($remote, PHP_URL_PORT);
$auth_header = ['Content-Type: application/json'];
$auth_body   = json_encode(['password' => 'testpass']);

$r = expect('inbox listing', 200, http('GET', "{$instance}/ap/inbox", $auth_body, $auth_header));
$inbox_page = json_body($r);
check('inbox response has items and unread', true, isset($inbox_page['items'], $inbox_page['unread']));
$first = $inbox_page['items'][0] ?? [];
check('inbox item has readable handle', '@bob@' . $hostport, $first['actor_handle'] ?? null);
check('inbox item kind is post', 'post', $first['kind'] ?? null);
check('inbox item has preview', true, str_contains((string) ($first['preview'] ?? ''), 'hello from bob'));
check('inbox item starts unread', 0, (int) ($first['is_read'] ?? 1));

// Classification: reply, mention, boost, like.
$reply_create = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#creates/reply-1",
    'type'     => 'Create',
    'actor'    => $actorU,
    'object'   => [
        'id'        => "{$remote}/notes/reply-1",
        'type'      => 'Note',
        'content'   => '<p>nice post</p>',
        'inReplyTo' => $second['object']['id'],
    ],
];
expect('signed reply', 202, sign_post($inbox, $reply_create, $key, $keyId));

$mention_create = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#creates/mention-1",
    'type'     => 'Create',
    'actor'    => $actorU,
    'object'   => [
        'id'      => "{$remote}/notes/mention-1",
        'type'    => 'Note',
        'content' => '<p>hey <span class="h-card"><a href="' . $instance . '/ap/actor">@alice</a></span></p>',
        'tag'     => [['type' => 'Mention', 'href' => "{$instance}/ap/actor", 'name' => '@alice']],
    ],
];
expect('signed mention', 202, sign_post($inbox, $mention_create, $key, $keyId));

$announce = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#announces/1",
    'type'     => 'Announce',
    'actor'    => $actorU,
    'object'   => $second['object']['id'],
];
expect('signed Announce', 202, sign_post($inbox, $announce, $key, $keyId));

$r = expect('inbox after interactions', 200, http('GET', "{$instance}/ap/inbox?limit=10", $auth_body, $auth_header));
$kinds = [];
$reply_item = null;
foreach (json_body($r)['items'] as $item) {
    $kinds[$item['kind']] = true;
    if (($item['kind'] ?? '') === 'reply') { $reply_item = $item; }
}
check('reply classified', true, isset($kinds['reply']));
check('mention classified', true, isset($kinds['mention']));
check('boost classified', true, isset($kinds['boost']));
check('like classified', true, isset($kinds['like']));
check('reply exposes parent url', $second['object']['id'], $reply_item['reply_to'] ?? null);

$unread_before = (int) json_body($r)['unread'];
check('unread count matches', true, $unread_before >= 4);

// Pagination: walk one item at a time.
$r = expect('inbox page 1', 200, http('GET', "{$instance}/ap/inbox?limit=1", $auth_body, $auth_header));
$p1 = json_body($r);
check('page holds one item', 1, count($p1['items']));
check('page exposes cursor', true, !empty($p1['next_before_id']));
$r = http('GET', "{$instance}/ap/inbox?limit=1&before_id=" . $p1['next_before_id'], $auth_body, $auth_header);
$p2 = json_body($r);
check('cursor returns next item', true, ($p2['items'][0]['id'] ?? 0) !== ($p1['items'][0]['id'] ?? 0));

// Per-item read, then mark all.
$target_id = (int) $p1['items'][0]['id'];
$r = post("{$instance}/ap/inbox/read", ['id' => $target_id, 'password' => 'testpass']);
$after_one = json_body($r)['unread'] ?? null;
check('per-item read lowers unread', true, $after_one === $unread_before - 1);

$r = expect('inbox mark all read', 200, http('GET', "{$instance}/ap/inbox?mark_read=1", $auth_body, $auth_header));
$pre_mark = array_map(fn($i) => (int) $i['is_read'], json_body($r)['items']);
check('mark_read returns pre-mark state', true, in_array(0, $pre_mark, true));
check('mark_read response keeps unread count', true, (int) json_body($r)['unread'] >= 0);

$r = http('GET', "{$instance}/ap/inbox", $auth_body, $auth_header);
check('inbox items read afterwards', 1, (int) (json_body($r)['items'][0]['is_read'] ?? 0));
check('unread is zero', 0, (int) json_body($r)['unread']);

expect('delete inbox item', 200, post("{$instance}/ap/inbox/delete", ['id' => $target_id, 'password' => 'testpass']));
check('inbox item deleted', 0, count(db($db_path, 'SELECT * FROM InboxItem WHERE id = ?', [$target_id])));

post("{$instance}/ap/inbox/delete", ['id' => 'read', 'password' => 'testpass']);
check('read inbox cleared', 0, count(db($db_path, 'SELECT * FROM InboxItem WHERE is_read = 1')));

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
check('new post delivered to follower inbox', true, str_contains((string) file_get_contents($rlog), 'delivery-marker'));

// Mastodon 4.3+ uses RFC 9421 message signatures instead of the legacy scheme.
$rfc_follow = $follow;
$rfc_follow['id'] = "{$actorU}#follows/rfc";
expect('rfc9421 signed Follow', 202, rfc9421_post($inbox, $rfc_follow, $key, $keyId));
check('rfc9421 follow stored', 1,
    count(db($db_path, 'SELECT * FROM Follower WHERE follow_id = ?', [$rfc_follow['id']])));

expect('signed Undo Follow', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#undos/2",
    'type'     => 'Undo',
    'actor'    => $actorU,
    'object'   => $follow,
], $key, $keyId));
check('follower removed', 0, count(db($db_path, 'SELECT * FROM Follower')));

// Unfollowing from the panel works by actor URI: no webfinger round-trip.
db($db_path, "INSERT INTO Following (actor_uri, inbox, data) VALUES (?, ?, ?)",
    [$actorU, "{$remote}/inbox", json_encode(['preferredUsername' => 'bob', 'name' => 'Bob'])]);

$r = expect('people lists following', 200, http('GET', "{$instance}/ap/people",
    json_encode(['password' => 'testpass']), ['Content-Type: application/json']));
check('following handle', '@bob@' . parse_url($remote, PHP_URL_HOST) . ':' . parse_url($remote, PHP_URL_PORT), json_body($r)['following'][0]['handle'] ?? null);

expect('unfollow by uri', 200, post("{$instance}/ap/action", ['user' => $actorU, 'action' => 'Unfollow', 'password' => 'testpass']));
check('following row removed', 0, count(db($db_path, 'SELECT * FROM Following')));
check('Undo delivered', true, str_contains((string) file_get_contents($rlog), '"type":"Undo"'));

expect('unfollow unknown uri rejected', 400, post("{$instance}/ap/action", ['user' => 'https://evil.example/u/x', 'action' => 'Unfollow', 'password' => 'testpass']));

section('Blocks');

$follow2 = $follow;
$follow2['id'] = "{$actorU}#follows/2";
$hostport = parse_url($remote, PHP_URL_HOST) . ':' . parse_url($remote, PHP_URL_PORT);

expect('follower before block', 202, sign_post($inbox, $follow2, $key, $keyId));
check('follower stored again', 1, count(db($db_path, 'SELECT * FROM Follower')));

expect('block actor', 200, post("{$instance}/ap/action", ['user' => $actorU, 'action' => 'Block', 'password' => 'testpass']));
check('block stored', 1, count(db($db_path, 'SELECT * FROM Block')));
check('blocking drops the follower', 0, count(db($db_path, 'SELECT * FROM Follower')));
check('Block activity delivered', true, str_contains((string) file_get_contents($rlog), '"type":"Block"'));

$r = expect('people lists blocked', 200, http('GET', "{$instance}/ap/people",
    json_encode(['password' => 'testpass']), ['Content-Type: application/json']));
check('blocked handle', '@bob@' . $hostport, json_body($r)['blocked'][0]['handle'] ?? null);

// Dropped before signature verification: no fetch, no write.
expect('blocked actor ignored', 202, http('POST', $inbox, json_encode($follow2), ['Content-Type: application/activity+json']));
check('blocked actor cannot follow', 0, count(db($db_path, 'SELECT * FROM Follower')));

expect('unblock actor', 200, post("{$instance}/ap/action", ['user' => $actorU, 'action' => 'Unblock', 'password' => 'testpass']));
check('block removed', 0, count(db($db_path, 'SELECT * FROM Block')));
check('Undo(Block) delivered', true, str_contains((string) file_get_contents($rlog), '"object":{"type":"Block"'));

section('Account deletion');

$follow3 = $follow;
$follow3['id'] = "{$actorU}#follows/3";
expect('follower before delete', 202, sign_post($inbox, $follow3, $key, $keyId));
check('follower stored before delete', 1, count(db($db_path, 'SELECT * FROM Follower')));

expect('signed Delete', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#deletes/1",
    'type'     => 'Delete',
    'actor'    => $actorU,
    'object'   => $actorU,
], $key, $keyId));
check('deleted account dropped as follower', 0, count(db($db_path, 'SELECT * FROM Follower')));

section('Follow state');

$accept_id = "{$actorU}#follows/accept-1";
db($db_path, "INSERT INTO Following (actor_uri, inbox, data, follow_id, state) VALUES (?, ?, ?, ?, 'pending')",
    [$actorU, "{$remote}/inbox", '{"preferredUsername":"bob"}', $accept_id]);

expect('signed Accept', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#accepts/1",
    'type'     => 'Accept',
    'actor'    => $actorU,
    'object'   => ['id' => $accept_id, 'type' => 'Follow', 'actor' => "{$instance}/ap/actor", 'object' => $actorU],
], $key, $keyId));
check('follow marked accepted', 'accepted',
    db($db_path, 'SELECT state FROM Following WHERE follow_id = ?', [$accept_id])[0]['state'] ?? null);

$reject_id = "{$actorU}#follows/reject-1";
db($db_path, "INSERT INTO Following (actor_uri, inbox, data, follow_id, state) VALUES (?, ?, ?, ?, 'pending')",
    ["{$actorU}#other", "{$remote}/inbox", '{}', $reject_id]);

expect('signed Reject', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#rejects/1",
    'type'     => 'Reject',
    'actor'    => $actorU,
    'object'   => ['id' => $reject_id, 'type' => 'Follow', 'actor' => "{$instance}/ap/actor", 'object' => $actorU],
], $key, $keyId));
check('rejected follow removed', 0, count(db($db_path, 'SELECT * FROM Following WHERE follow_id = ?', [$reject_id])));

// Undo that references the Follow activity id instead of the inline object.
$undo_follow_id = "{$actorU}#follows/undo-id";
expect('follow with tracked id', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => $undo_follow_id,
    'type'     => 'Follow',
    'actor'    => $actorU,
    'object'   => "{$instance}/ap/actor",
], $key, $keyId));
check('follower stored with follow id', true,
    (bool) db($db_path, 'SELECT * FROM Follower WHERE follow_id = ?', [$undo_follow_id]));

expect('undo by activity id', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#undos/by-id",
    'type'     => 'Undo',
    'actor'    => $actorU,
    'object'   => $undo_follow_id,
], $key, $keyId));
check('undo-by-id removed follower', 0, count(db($db_path, 'SELECT * FROM Follower')));

section('Account moves');

db($db_path, "INSERT OR REPLACE INTO Follower (actor_uri, inbox, data) VALUES (?, ?, '{}')", [$actorU, "{$remote}/inbox"]);
db($db_path, "INSERT OR REPLACE INTO Following (actor_uri, inbox, data, state) VALUES (?, ?, '{}', 'accepted')", [$actorU, "{$remote}/inbox"]);

expect('signed Move', 202, sign_post($inbox, [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id'       => "{$actorU}#moves/1",
    'type'     => 'Move',
    'actor'    => $actorU,
    'object'   => $actorU,
    'target'   => "{$remote}/u/carol",
], $key, $keyId));

check('following moved', 1, count(db($db_path, 'SELECT * FROM Following WHERE actor_uri = ?', ["{$remote}/u/carol"])));
check('old following removed', 0, count(db($db_path, 'SELECT * FROM Following WHERE actor_uri = ?', [$actorU])));
check('follower moved', 1, count(db($db_path, 'SELECT * FROM Follower WHERE actor_uri = ?', ["{$remote}/u/carol"])));
check('old follower removed', 0, count(db($db_path, 'SELECT * FROM Follower WHERE actor_uri = ?', [$actorU])));

section('Delivery retries');

$fail_flag = $rlog . '.fail';
file_put_contents($fail_flag, '1');
db($db_path, "INSERT INTO Follower (actor_uri, inbox, data) VALUES (?, ?, '{}')", ["{$actorU}#retry", "{$remote}/inbox"]);

expect('post while follower fails', 200, post("{$instance}/ap/post", ['content' => 'retry-marker', 'password' => 'testpass']));
$queue = db($db_path, 'SELECT * FROM Delivery');
check('delivery left in queue', 1, count($queue));
check('attempt recorded', true, (int) ($queue[0]['attempts'] ?? 0) >= 1);
check('failed delivery not marked sent', false, str_contains((string) file_get_contents($rlog), 'retry-marker'));

unlink($fail_flag);
db($db_path, "UPDATE Delivery SET next_try = datetime('now', '-1 minute')");

$worker = dirname(dirname($db_path)) . '/scripts/ops/deliver.php';
$out    = [];
$code   = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' 2>&1', $out, $code);
check('worker exits cleanly', 0, $code);
check('worker reports a delivery', true, str_contains(implode("\n", $out), 'delivered=1'));
check('queue drained', 0, count(db($db_path, 'SELECT * FROM Delivery')));
check('retry reached the inbox', true, str_contains((string) file_get_contents($rlog), 'retry-marker'));
db($db_path, 'DELETE FROM Follower WHERE actor_uri = ?', ["{$actorU}#retry"]);

section('Dead follower cleanup');

$root_dir = dirname(dirname($db_path));
$sec_log  = $root_dir . '/sys/logs/security.log';
@unlink($sec_log);

file_put_contents($rlog . '.fail', '1');
db($db_path, "INSERT OR REPLACE INTO Follower (actor_uri, inbox, data) VALUES (?, ?, '{}')",
    ["{$actorU}#dead", "{$remote}/inbox"]);
db($db_path, "INSERT INTO Delivery (inbox, payload, attempts, next_try) VALUES (?, ?, 9, datetime('now', '-1 minute'))",
    ["{$remote}/inbox", json_encode(['type' => 'Create', 'actor' => "{$actorU}#dead"])]);

$out  = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root_dir . '/scripts/ops/deliver.php') . ' 2>&1', $out, $code);
check('worker survives a dead inbox', 0, $code);
check('dead follower removed', 0, count(db($db_path, 'SELECT * FROM Follower WHERE actor_uri = ?', ["{$actorU}#dead"])));
check('dead delivery dropped', 0, count(db($db_path, 'SELECT * FROM Delivery')));
check('drop logged', true, str_contains((string) @file_get_contents($sec_log), 'Delivery.Dropped'));
unlink($rlog . '.fail');

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

$jar = '';
$r = expect('prefixed panel', 200, http('GET', "{$prefix}/sufi/sufi/", null, [], $jar));
check('prefixed login form', true, str_contains($r[2], 'name="password"'));
$login_csrf = form_token($r[2]);

$r = expect('prefixed login', 302, post("{$prefix}/sufi/sufi/", ['password' => 'testpass', 'csrf_token' => $login_csrf], $jar));
check('prefixed login redirect', "{$prefix}/sufi/sufi/", $r[1]['location'][0] ?? null);

$r = expect('prefixed panel with session', 200, http('GET', "{$prefix}/sufi/sufi/", null, [], $jar));
check('prefixed panel BASE', true, str_contains($r[2], 'const BASE = ' . json_encode($prefix . '/sufi')));
$csrf = token($r[2]);
$r = expect('prefixed post', 200, post("{$prefix}/sufi/ap/post", ['content' => 'prefixed', 'csrf_token' => $csrf], $jar));
check('prefixed post id', true, str_starts_with((string) (json_body($r)['object']['id'] ?? ''), "{$prefix}/sufi/ap/post/"));
check('prefixed post stored', 1, count(db($pdb_path, 'SELECT * FROM Post')));

// This instance refuses plain-http/private fetches, so the remote key cannot be
// loaded and the activity must be rejected.
$prefix_root = dirname(dirname($pdb_path));
expect('private actor rejected', 401, sign_post("{$prefix}/sufi/ap/inbox", $follow, $key, $keyId));
check('blocked fetch logged', true,
    str_contains((string) @file_get_contents($prefix_root . '/sys/logs/security.log'), 'Fetch.Blocked'));

// Rotating sys/private.pem must update the served public key by itself.
$new_res = openssl_pkey_new(['private_key_bits' => 2048]);
openssl_pkey_export($new_res, $new_priv);
file_put_contents((string) getenv('SUFI_PREFIX_KEY'), $new_priv);
$r = expect('actor after key rotation', 200, http('GET', "{$prefix}/sufi/ap/actor"));
check('rotated key is served', trim(openssl_pkey_get_details($new_res)['key']), trim(json_body($r)['publicKey']['publicKeyPem'] ?? ''));
check('actor not duplicated', 1, count(db($pdb_path, 'SELECT * FROM Actor')));

// ── maintenance scripts ───────────────────────────────────────────────────────

section('Maintenance');

$schema = db($db_path, 'PRAGMA user_version');
check('schema version', 3, (int) ($schema[0]['user_version'] ?? 0));
$columns = array_column(db($db_path, 'PRAGMA table_info(InboxItem)'), 'name');
check('inbox read column exists', true, in_array('is_read', $columns, true));

$root_dir = dirname(dirname($db_path));
db($db_path, "INSERT INTO InboxItem (type, actor_uri, data, created_at) VALUES ('Create', 'https://old.example/u/x', '{}', datetime('now', '-200 days'))");
$old_count = count(db($db_path, 'SELECT * FROM InboxItem'));

$out  = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root_dir . '/scripts/ops/prune.php') . ' 2>&1', $out, $code);
check('prune exits cleanly', 0, $code);
check('old inbox items pruned', $old_count - 1, count(db($db_path, 'SELECT * FROM InboxItem')));

// Backfill of actor handles for items stored before handles existed.
db($db_path, "INSERT INTO InboxItem (type, actor_uri, data) VALUES ('Create', ?, '{}')", [$actorU]);
db($db_path, "UPDATE InboxItem SET actor_handle = '' WHERE actor_uri = ?", [$actorU]);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root_dir . '/scripts/ops/backfill.php') . ' 2>&1', $out, $code);
check('backfill exits cleanly', 0, $code);
$backfilled = db($db_path, 'SELECT actor_handle FROM InboxItem WHERE actor_uri = ? AND actor_handle <> ? LIMIT 1', [$actorU, '']);
check('backfill filled the handle', '@bob@' . $hostport, $backfilled[0]['actor_handle'] ?? null);

putenv('PHP=' . PHP_BINARY);
exec('bash ' . escapeshellarg($root_dir . '/scripts/ops/backup.sh') . ' 2>&1', $out, $code);
check('backup exits cleanly', 0, $code);
$backups = glob($root_dir . '/sys/backups/sufi-*.sqlite') ?: [];
check('backup file created', 1, count($backups));
check('backup is a sqlite database', true, $backups && (new PDO('sqlite:' . $backups[0]))->query('PRAGMA user_version')->fetchColumn() !== false);

// ── login rate limiting (last: it locks the IP out for a while) ───────────────

section('Login rate limiting');

for ($i = 0; $i < 5; $i++) {
    http('POST', "{$instance}/sufi/", http_build_query(['password' => 'wrong-' . $i]),
        ['Content-Type: application/x-www-form-urlencoded']);
}
$r = http('POST', "{$instance}/sufi/", http_build_query(['password' => 'wrong-again']),
    ['Content-Type: application/x-www-form-urlencoded']);
check('blocked after 5 failures', 429, $r[0]);

// ── summary ───────────────────────────────────────────────────────────────────

echo "\n";
if ($failures === 0) {
    echo "\033[32m{$count} checks passed\033[0m\n";
    exit(0);
}
echo "\033[31m{$failures} of {$count} checks failed\033[0m\n";
exit(1);
