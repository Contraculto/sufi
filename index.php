<?php
namespace SUFI;

require __DIR__ . "/sys/config.php";
require __DIR__ . "/sys/database.php";
require __DIR__ . "/templates/actor.php";
require __DIR__ . "/templates/webfinger.php";

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// Defaults for config.php files written before these settings existed.
$base_path     = rtrim((string) ($base_path ?? ''), '/');
$base_url      = $base_url ?? "https://{$server}/";
$cookie_secure = $cookie_secure ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (!file_exists(__DIR__ . '/sys/private.pem')) {
    http_response_code(500);
    die('Missing RSA private key. Generate it with: openssl genrsa -out sys/private.pem 2048');
}

$db_handler = System\Database\connect_database();
System\Database\ensure_actor(
    $db_handler,
    $server,
    $username,
    $base_url,
    $key_private,
    $actor_name    ?? $username,
    $actor_summary ?? '',
    $actor_icon    ?? ''
);
$user_data  = System\Database\get_user_data($db_handler);

session_set_cookie_params([
    'httponly' => true,
    'secure'   => $cookie_secure,
    'samesite' => 'Strict',
]);
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// ── Router ────────────────────────────────────────────────────────────────────
$request = strtok($_SERVER["REQUEST_URI"], '?');

// Strip subdirectory prefix when SUFI is deployed inside an existing site.
// The boundary check matters: with $base_path = '/sufi', a request for
// '/sufi2/ap/actor' must not be treated as SUFI.
if ($base_path !== '' && ($request === $base_path || str_starts_with($request, $base_path . '/'))) {
    $request = substr($request, strlen($base_path)) ?: '/';
}

// Canonical panel URL, always with a trailing slash, e.g. https://host/sufi/
$panel_url = $base_url . 'sufi/';

// Normalize webfinger — the query string was already removed
if (str_contains($request, "well-known/webfinger")) {
    $request = "/.well-known/webfinger";
}

switch ($request) {

    case "/ap/actor":
        header("Content-Type: application/activity+json; charset=utf-8");
        echo Templates\actor($user_data);
        break;

    case "/.well-known/webfinger":
        header("Content-Type: application/jrd+json; charset=utf-8");
        echo Templates\webfinger($user_data);
        break;

    case "/.well-known/host-meta":
        meta();
        break;

    case "/ap/inbox":
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            inbox();
        } else {
            inbox_items();
        }
        break;

    case "/ap/outbox":
        require __DIR__ . "/templates/outbox.php";
        header("Content-Type: application/activity+json; charset=utf-8");
        echo Templates\outbox($user_data);
        break;

    case "/ap/followers":
        require __DIR__ . "/templates/followers.php";
        header("Content-Type: application/activity+json; charset=utf-8");
        echo Templates\followers($user_data);
        break;

    case "/ap/following":
        require __DIR__ . "/templates/following.php";
        header("Content-Type: application/activity+json; charset=utf-8");
        echo Templates\following($user_data);
        break;

    case "/ap/likes":
        require __DIR__ . "/templates/likes.php";
        header("Content-Type: application/activity+json; charset=utf-8");
        echo Templates\likes($user_data);
        break;

    case "/ap/people":
        people();
        break;

    case "/ap/action":
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { action_users(); }
        break;

    case "/ap/post":
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            create_post_endpoint();
        } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            list_posts();
        }
        break;

    case "/ap/follow":
        remote_follow();
        break;

    case "/sufi":
        // Canonical slash so the panel's relative links resolve correctly.
        header('Location: ' . $panel_url);
        die();

    case "/sufi/":
        if (!$panel_enabled) { http_response_code(404); echo "Not found"; break; }

        // Panel actions post back to this same URL, so no extra web-server
        // rewrite rules are needed for login or logout.
        if (isset($_GET['logout'])) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'],
                    $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            header('Location: ' . $panel_url);
            die();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
            if (hash_equals($password, (string) $_POST['password'])) {
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                header('Location: ' . $panel_url);
            } else {
                header('Location: ' . $panel_url . '?error=1');
            }
            die();
        }

        require __DIR__ . "/templates/panel.php";
        break;

    default:
        if ($request === "/@{$username}" || $request === "/{$username}" || $request === "/") {
            require __DIR__ . "/templates/home.php";
        } elseif (preg_match('#^/ap/post/([a-f0-9-]+)$#', $request, $m)) {
            $guid   = $m[1];
            $action = $_POST['action'] ?? ($body['action'] ?? null);
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
                delete_post_endpoint($guid);
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
                update_post_endpoint($guid);
            } else {
                show_post($guid);
            }
        } else {
            http_response_code(404);
            echo "Not found";
        }
        break;
}

// ════════════════════════════════════════════════════════════════════════════════
// Inbox
// ════════════════════════════════════════════════════════════════════════════════

function inbox() {
    global $body, $db_handler, $base_url;

    // Every activity — Follow, Undo, Create, Like, anything — must be signed by
    // the actor that claims to have sent it. Verify before touching the database:
    // an unauthenticated inbox is a spam and impersonation vector.
    if (!verifyHTTPSignature()) {
        http_response_code(401);
        die();
    }

    $type      = $body["type"]  ?? null;
    $actor_uri = $body["actor"] ?? null;

    if (!is_string($type) || $type === '' || !is_string($actor_uri) || $actor_uri === '') {
        http_response_code(400);
        die();
    }

    // Store incoming interactions (all verified at this point)
    if (in_array($type, ["Create", "Announce", "Like"], true)) {
        $obj     = $body["object"] ?? null;
        $obj_uri = is_array($obj) ? ($obj["id"] ?? null) : (is_string($obj) ? $obj : null);

        if ($type === "Like" && is_string($obj_uri) && $obj_uri !== '') {
            System\Database\add_like($db_handler, $obj_uri, $actor_uri);
        }
        System\Database\add_inbox_item($db_handler, $type, $actor_uri, $obj_uri, $body);
        http_response_code(202);
        die();
    }

    if ($type === "Follow") {
        $actor_data     = getDataFromURl($actor_uri);
        $follower_inbox = $actor_data["endpoints"]["sharedInbox"]
                       ?? $actor_data["inbox"]
                       ?? null;

        if (!$follower_inbox) {
            http_response_code(400);
            die();
        }

        System\Database\add_follower($db_handler, $actor_uri, $follower_inbox, $actor_data ?? []);

        $guid    = uuid();
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "{$base_url}{$guid}",
            "type"     => "Accept",
            "actor"    => "{$base_url}ap/actor",
            "object"   => [
                "@context" => "https://www.w3.org/ns/activitystreams",
                "id"       => $body["id"] ?? null,
                "type"     => "Follow",
                "actor"    => $actor_uri,
                "object"   => "{$base_url}ap/actor",
            ],
        ];
        sendMessageToSingle($follower_inbox, $message);
        save_log("Follow.Accept", "actor: {$actor_uri}");
        http_response_code(202);
        die();
    }

    if ($type === "Undo") {
        if (!System\Database\get_follower($db_handler, $actor_uri)) {
            http_response_code(202);
            die();
        }
        undo($body);
        http_response_code(202);
        die();
    }

    // Everything else is accepted and ignored — SUFI is a publishing server.
    http_response_code(202);
    die();
}

function inbox_items() {
    global $db_handler;

    if (!panel_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        return;
    }

    $items = System\Database\get_inbox_items($db_handler);
    foreach ($items as &$item) {
        $item['data'] = json_decode($item['data'], true);
    }
    header('Content-Type: application/json');
    echo json_encode($items);
}

/**
 * Panel view of followers/following: readable handles instead of raw actor URIs.
 */
function people() {
    global $db_handler;

    if (!panel_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        return;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'followers' => people_list(System\Database\get_followers($db_handler)),
        'following' => people_list(System\Database\get_following($db_handler)),
    ]);
}

function people_list(array $rows) {
    $people = [];

    foreach ($rows as $row) {
        $data  = json_decode($row['data'] ?? '{}', true) ?: [];
        $parts = parse_url((string) $row['actor_uri']);
        $host  = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $user  = $data['preferredUsername'] ?? basename((string) ($parts['path'] ?? ''));

        $url = $data['url'] ?? ($data['id'] ?? $row['actor_uri']);
        if (!is_string($url)) { $url = $row['actor_uri']; }

        $people[] = [
            'uri'    => $row['actor_uri'],
            'handle' => '@' . $user . '@' . $host,
            'name'   => is_string($data['name'] ?? null) ? $data['name'] : '',
            'url'    => $url,
        ];
    }

    return $people;
}

// ════════════════════════════════════════════════════════════════════════════════
// Posts
// ════════════════════════════════════════════════════════════════════════════════

function create_post_endpoint() {
    global $body;

    if (!panel_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        return;
    }
    require_csrf_if_session();

    $content  = $_POST['content']     ?? ($body['content']     ?? null);
    $in_reply = $_POST['in_reply_to'] ?? ($body['in_reply_to'] ?? null);

    if (!$content) {
        http_response_code(400);
        echo "Missing content";
        return;
    }

    $activity = create_post($content, $in_reply);
    header('Content-Type: application/activity+json; charset=utf-8');
    echo json_encode($activity);
}

function create_post($content, $in_reply_to = null) {
    global $base_url, $db_handler;

    $guid      = uuid();
    $base      = $base_url;
    $published = date('c');

    $note = [
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => "{$base}ap/post/{$guid}",
        "type"         => "Note",
        "published"    => $published,
        "attributedTo" => "{$base}ap/actor",
        "content"      => $content,
        "to"           => ["https://www.w3.org/ns/activitystreams#Public"],
        "cc"           => ["{$base}ap/followers"],
    ];
    if ($in_reply_to) { $note["inReplyTo"] = $in_reply_to; }

    $activity = [
        "@context"  => "https://www.w3.org/ns/activitystreams",
        "id"        => "{$base}ap/activity/{$guid}",
        "type"      => "Create",
        "actor"     => "{$base}ap/actor",
        "published" => $published,
        "to"        => ["https://www.w3.org/ns/activitystreams#Public"],
        "cc"        => ["{$base}ap/followers"],
        "object"    => $note,
    ];

    System\Database\add_post($db_handler, $guid, 'Note', $content, $published, $in_reply_to, $activity);
    sendToFollowers($activity);

    return $activity;
}

function show_post($guid) {
    global $db_handler;

    $post = System\Database\get_post($db_handler, $guid);
    if (!$post) {
        http_response_code(404);
        echo "Not found";
        return;
    }

    // A post URL identifies the Note, not the Create/Update wrapper around it.
    $data = json_decode($post['data'], true) ?: [];
    if (in_array($data['type'] ?? null, ['Create', 'Update'], true) && is_array($data['object'] ?? null)) {
        $data = $data['object'];
    }

    header('Content-Type: application/activity+json; charset=utf-8');
    echo json_encode($data);
}

function list_posts() {
    global $db_handler;

    if (!panel_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        return;
    }

    $posts = System\Database\get_posts($db_handler);
    foreach ($posts as &$p) { $p['data'] = json_decode($p['data'], true); }
    header('Content-Type: application/json');
    echo json_encode($posts);
}

function delete_post_endpoint($guid) {
    global $base_url, $db_handler;

    if (!panel_auth()) { http_response_code(401); echo "Unauthorized"; return; }
    require_csrf_if_session();

    if (!System\Database\get_post($db_handler, $guid)) {
        http_response_code(404); echo "Not found"; return;
    }

    $activity = [
        "@context" => "https://www.w3.org/ns/activitystreams",
        "id"       => "{$base_url}ap/activity/" . uuid(),
        "type"     => "Delete",
        "actor"    => "{$base_url}ap/actor",
        "to"       => ["https://www.w3.org/ns/activitystreams#Public"],
        "object"   => "{$base_url}ap/post/{$guid}",
    ];
    sendToFollowers($activity);
    System\Database\delete_post($db_handler, $guid);
    echo "Deleted";
}

function update_post_endpoint($guid) {
    global $body, $base_url, $db_handler;

    if (!panel_auth()) { http_response_code(401); echo "Unauthorized"; return; }
    require_csrf_if_session();

    $content = $_POST['content'] ?? ($body['content'] ?? null);
    if (!$content) { http_response_code(400); echo "Missing content"; return; }

    $post = System\Database\get_post($db_handler, $guid);
    if (!$post) { http_response_code(404); echo "Not found"; return; }

    $updated   = date('c');
    $base      = $base_url;
    $actor_url = "{$base}ap/actor";

    $note = [
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => "{$base}ap/post/{$guid}",
        "type"         => "Note",
        "published"    => $post['published'],
        "updated"      => $updated,
        "attributedTo" => $actor_url,
        "content"      => $content,
        "to"           => ["https://www.w3.org/ns/activitystreams#Public"],
        "cc"           => ["{$base}ap/followers"],
    ];
    $activity = [
        "@context"  => "https://www.w3.org/ns/activitystreams",
        "id"        => "{$base}ap/activity/" . uuid(),
        "type"      => "Update",
        "actor"     => $actor_url,
        "published" => $updated,
        "to"        => ["https://www.w3.org/ns/activitystreams#Public"],
        "cc"        => ["{$base}ap/followers"],
        "object"    => $note,
    ];

    System\Database\update_post($db_handler, $guid, $content, $activity);
    sendToFollowers($activity);
    header('Content-Type: application/activity+json; charset=utf-8');
    echo json_encode($activity);
}

// ════════════════════════════════════════════════════════════════════════════════
// Social actions — follow / unfollow / block / unblock
// ════════════════════════════════════════════════════════════════════════════════

function action_users() {
    global $base_url, $db_handler;

    if (!panel_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        return;
    }
    require_csrf_if_session();

    $user   = $_POST["user"]   ?? null;
    $action = $_POST["action"] ?? null;

    if (!is_string($user) || $user === '') {
        http_response_code(400);
        echo "Missing user";
        return;
    }

    if (!in_array($action, ["Follow", "Unfollow", "Block", "Unblock"], true)) {
        http_response_code(400);
        echo "Unsupported action";
        return;
    }

    if (preg_match('#^https?://#i', $user)) {
        // Unfollowing/blocking by actor URI. Only actors we already follow are
        // accepted, so the panel cannot be used to send signed activities to
        // arbitrary inboxes.
        $row = System\Database\get_following_actor($db_handler, $user);
        if (!$row) {
            http_response_code(400);
            echo "Not following that actor";
            return;
        }
        $profileUrl   = $user;
        $profileInbox = $row["inbox"];
    } else {
        $details      = getUserDetails($user);
        $profileInbox = $details["inbox"] ?? null;
        $profileUrl   = $details["profile"] ?? null;
    }

    if (!$profileUrl || !$profileInbox) {
        http_response_code(400);
        echo "Could not resolve that handle";
        return;
    }

    $guid         = uuid();
    $actor_url    = "{$base_url}ap/actor";

    if ($action === "Follow") {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "{$base_url}{$guid}",
            "type"     => "Follow",
            "actor"    => $actor_url,
            "object"   => $profileUrl,
        ];
    } elseif ($action === "Unfollow") {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "{$base_url}{$guid}",
            "type"     => "Undo",
            "actor"    => $actor_url,
            "object"   => ["type" => "Follow", "actor" => $actor_url, "object" => $profileUrl],
        ];
    } elseif ($action === "Block") {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "{$base_url}{$guid}",
            "type"     => "Block",
            "actor"    => $actor_url,
            "object"   => $profileUrl,
            "to"       => $profileUrl,
        ];
    } else {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "{$base_url}{$guid}",
            "type"     => "Undo",
            "actor"    => $actor_url,
            "object"   => ["type" => "Block", "actor" => $actor_url, "object" => $profileUrl],
        ];
    }

    sendMessageToSingle($profileInbox, $message);

    if ($action === "Follow") {
        $profile_data = getDataFromURl($profileUrl);
        System\Database\add_following($db_handler, $profileUrl, $profileInbox, $profile_data ?? []);
    } elseif ($action === "Unfollow" || $action === "Block") {
        System\Database\remove_following($db_handler, $profileUrl);
    }

    echo htmlspecialchars("{$action} sent to {$user}");
}

// ════════════════════════════════════════════════════════════════════════════════
// Remote follow redirect (subscribe template)
// ════════════════════════════════════════════════════════════════════════════════

function remote_follow() {
    $uri = $_GET['uri'] ?? null;
    if (!$uri) {
        http_response_code(400);
        echo "Missing uri parameter";
        return;
    }

    $parts = explode('@', ltrim($uri, '@'));
    if (count($parts) === 2) {
        $remote_server = $parts[1];
        global $base_url;
        $actor_url = urlencode("{$base_url}ap/actor");
        header("Location: https://{$remote_server}/authorize_interaction?uri={$actor_url}");
        die();
    }

    http_response_code(400);
    echo "Invalid uri";
}

// ════════════════════════════════════════════════════════════════════════════════
// Undo helper
// ════════════════════════════════════════════════════════════════════════════════

function undo($message) {
    global $db_handler;

    $object      = $message["object"] ?? null;
    $object_type = is_array($object) ? ($object["type"] ?? null) : null;
    $actor       = is_array($object) ? ($object["actor"] ?? $message["actor"]) : ($message["actor"] ?? null);

    if (!is_string($actor) || $actor === '') {
        return;
    }

    if ($object_type === "Follow") {
        System\Database\remove_follower($db_handler, $actor);
    } elseif ($object_type === "Like") {
        $liked = $object["object"] ?? null;
        $liked = is_array($liked) ? ($liked["id"] ?? null) : $liked;
        if (is_string($liked) && $liked !== '') {
            System\Database\remove_like($db_handler, $liked, $actor);
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// HTTP signing and sending
// ════════════════════════════════════════════════════════════════════════════════

function generate_signed_headers($message, $host, $path, $method) {
    global $base_url, $key_private;

    $keyId  = "{$base_url}ap/actor#main-key";
    $signer = openssl_get_privatekey($key_private);
    $date   = date("D, d M Y H:i:s \G\M\T");

    if ($method === "POST") {
        $message_json = json_encode($message);
        $digest       = base64_encode(hash("sha256", $message_json, true));
        $stringToSign = "(request-target): post {$path}\nhost: {$host}\ndate: {$date}\ndigest: SHA-256={$digest}";

        openssl_sign($stringToSign, $signature, $signer, OPENSSL_ALGO_SHA256);
        $sig_b64 = base64_encode($signature);
        $sig_hdr = "keyId=\"{$keyId}\",algorithm=\"rsa-sha256\",headers=\"(request-target) host date digest\",signature=\"{$sig_b64}\"";

        return [
            "Host: {$host}",
            "Date: {$date}",
            "Digest: SHA-256={$digest}",
            "Signature: {$sig_hdr}",
            "Content-Type: application/activity+json",
            "Accept: application/activity+json",
            "User-Agent: SUFI (+{$base_url})",
        ];
    }

    $stringToSign = "(request-target): get {$path}\nhost: {$host}\ndate: {$date}";
    openssl_sign($stringToSign, $signature, $signer, OPENSSL_ALGO_SHA256);
    $sig_b64 = base64_encode($signature);
    $sig_hdr = "keyId=\"{$keyId}\",algorithm=\"rsa-sha256\",headers=\"(request-target) host date\",signature=\"{$sig_b64}\"";

    return [
        "Host: {$host}",
        "Date: {$date}",
        "Signature: {$sig_hdr}",
        "Accept: application/activity+json, application/json",
        "User-Agent: SUFI (+{$base_url})",
    ];
}

function sendMessageToSingle($inbox, $message) {
    $parsed = parse_url($inbox);
    if (empty($parsed['host'])) { return; }

    $host    = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    $path    = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    $headers = generate_signed_headers($message, $host, $path, 'POST');
    $json    = json_encode($message);

    $ctx = stream_context_create(['http' => [
        'method'         => 'POST',
        'header'         => implode("\r\n", $headers),
        'content'        => $json,
        'ignore_errors'  => true,
        'timeout'        => 10,
    ]]);
    $response = @file_get_contents($inbox, false, $ctx);

    $status = $http_response_header[0] ?? '';
    if ($response === false) {
        save_log('Delivery.Fail', "inbox: {$inbox}");
    } elseif (!preg_match('#^HTTP/\S+\s+2\d\d#', $status)) {
        save_log('Delivery.Reject', "inbox: {$inbox} status: {$status}");
    }
}

function sendToFollowers($message) {
    global $db_handler;
    foreach (System\Database\get_followers($db_handler) as $follower) {
        sendMessageToSingle($follower['inbox'], $message);
    }
}

function getDataFromURl($url) {
    static $cache = [];
    if (array_key_exists($url, $cache)) { return $cache[$url]; }

    $fetch   = strtok($url, '#');
    $parsed  = parse_url($fetch);
    if (empty($parsed['host'])) { return $cache[$url] = null; }

    $host    = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    $path    = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    $headers = generate_signed_headers(null, $host, $path, 'GET');

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout'       => 10,
    ]]);
    $response = @file_get_contents($fetch, false, $ctx);
    return $cache[$url] = ($response ? json_decode($response, true) : null);
}

function getUserDetails($handle) {
    $parts = explode("@", ltrim((string) $handle, "@"));
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return ["profile" => null, "inbox" => null];
    }
    [$follow_name, $follow_server] = $parts;

    $webfinger_url = "https://{$follow_server}/.well-known/webfinger?resource=acct:{$follow_name}@{$follow_server}";
    $webfinger     = getDataFromURl($webfinger_url);

    $profile_url   = null;
    $profile_inbox = null;
    foreach ($webfinger["links"] ?? [] as $link) {
        if ($link["rel"] === "self") { $profile_url = $link["href"]; }
        if ($link["rel"] === "inbox") { $profile_inbox = $link["href"]; }
        if ($link["rel"] === "sharedInbox") { $profile_inbox = $link["href"]; }
    }

    if (!$profile_inbox && $profile_url) {
        $profile = getDataFromURl($profile_url);
        $profile_inbox = $profile["endpoints"]["sharedInbox"] ?? $profile["inbox"] ?? null;
    }

    return ["profile" => $profile_url, "inbox" => $profile_inbox];
}

// ════════════════════════════════════════════════════════════════════════════════
// HTTP Signature verification
// ════════════════════════════════════════════════════════════════════════════════

function verifyHTTPSignature() {
    global $input, $body;

    $type      = urlencode($body["type"] ?? "unknown");
    $method    = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");
    $headers   = array_change_key_case(get_request_headers(), CASE_LOWER);
    $actor_uri = $body["actor"] ?? null;

    $fail = function (string $reason, string $detail = "") use ($type) {
        save_log("{$type}.Signature.{$reason}", $detail);
        return false;
    };

    if (!is_string($actor_uri) || $actor_uri === "") {
        return $fail("NoActor");
    }

    if (!isset($headers["date"])) {
        return $fail("NoDate", print_r($headers, true));
    }

    $header_datetime = \DateTime::createFromFormat('D, d M Y H:i:s T', $headers["date"]);
    if (!$header_datetime) {
        return $fail("BadDate", $headers["date"]);
    }

    if (abs(time() - $header_datetime->getTimestamp()) > 3600) {
        return $fail("DateDrift", $headers["date"]);
    }

    if (isset($body["published"]) && is_string($body["published"])) {
        try {
            $published = new \DateTime($body["published"]);
        } catch (\Exception $e) {
            return $fail("BadPublished", $body["published"]);
        }
        if (abs($published->getTimestamp() - $header_datetime->getTimestamp()) > 120) {
            return $fail("PublishedSkew", $body["published"]);
        }
    }

    $sig_header = $headers["signature"] ?? null;
    if (!$sig_header) {
        return $fail("NoSignature");
    }

    preg_match_all('/(\w+)="([^"]*)"/', $sig_header, $m);
    $parts = array_combine($m[1], $m[2]) ?: [];

    if (empty($parts["keyId"]) || empty($parts["signature"]) || empty($parts["headers"])) {
        return $fail("Malformed", $sig_header);
    }

    $signed_headers = preg_split('/\s+/', strtolower(trim($parts["headers"])));

    // The digest must be present and covered by the signature, otherwise the
    // body could be swapped for something the actor never sent.
    if ($method === "POST") {
        if (!isset($headers["digest"])) {
            return $fail("NoDigest");
        }
        if (!in_array("digest", $signed_headers, true)) {
            return $fail("DigestUnsigned", $parts["headers"]);
        }

        [$digest_algorithm, $digest_hash] = array_pad(explode("=", $headers["digest"], 2), 2, "");
        $algo = match (strtoupper($digest_algorithm)) {
            "SHA-256", "HS2019" => "sha256",
            "SHA-512"           => "sha512",
            default             => strtolower($digest_algorithm),
        };
        if (!hash_equals(base64_encode(hash($algo, $input, true)), $digest_hash)) {
            return $fail("DigestFail");
        }
    }

    $signed = "";
    foreach ($signed_headers as $h) {
        if ($h === "(request-target)") {
            $signed .= "(request-target): " . strtolower($method) . " " . $_SERVER["REQUEST_URI"] . "\n";
        } elseif ($h === "host") {
            $signed .= "host: " . strtolower($_SERVER["HTTP_HOST"] ?? "") . "\n";
        } else {
            $signed .= "{$h}: " . ($headers[$h] ?? "") . "\n";
        }
    }
    $signed = rtrim($signed);

    $key_data   = getDataFromURl(strtok($parts["keyId"], "#"));
    $public_key = $key_data["publicKey"]["publicKeyPem"] ?? null;
    if (!$public_key) {
        return $fail("NoPublicKey", $parts["keyId"]);
    }

    $actor_data    = getDataFromURl($actor_uri) ?: [];
    $actor_pub_key = $actor_data["publicKey"]["publicKeyPem"] ?? null;
    if (!$actor_pub_key || !hash_equals($public_key, $actor_pub_key)) {
        return $fail("KeyMismatch", $parts["keyId"]);
    }

    // keyId must live on the actor's host, otherwise one instance could sign
    // activities on behalf of an actor it does not own.
    $key_host   = strtolower((string) parse_url($parts["keyId"], PHP_URL_HOST));
    $actor_host = strtolower((string) parse_url($actor_uri, PHP_URL_HOST));
    if ($key_host === "" || $key_host !== $actor_host) {
        return $fail("HostMismatch", "keyId: {$parts["keyId"]} actor: {$actor_uri}");
    }

    $algorithm = match (strtolower($parts["algorithm"] ?? "rsa-sha256")) {
        "rsa-sha512" => OPENSSL_ALGO_SHA512,
        default      => OPENSSL_ALGO_SHA256,
    };

    $result = openssl_verify($signed, base64_decode($parts["signature"]), $public_key, $algorithm);
    if ($result !== 1) {
        return $fail("Invalid", "openssl: {$result}");
    }

    return true;
}

// ════════════════════════════════════════════════════════════════════════════════
// Utilities
// ════════════════════════════════════════════════════════════════════════════════

/**
 * Request headers as a lower-case-friendly associative array. Works on Apache
 * (getallheaders) and on PHP-FPM/Nginx by normalising $_SERVER entries.
 */
function get_request_headers(): array {
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headers[str_replace('_', '-', substr($key, 5))] = $value;
        } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $headers[str_replace('_', '-', $key)] = $value;
        }
    }
    return $headers;
}

/** Password supplied by a script/API client, from POST form data or a JSON body. */
function request_password(): ?string {
    global $body;
    return $_POST['password'] ?? ($body['password'] ?? null);
}

function panel_auth() {
    global $password;

    if (!empty($_SESSION['auth'])) {
        return true;
    }
    // API/script access: allow a valid password in the request body.
    return request_password() === $password;
}

/**
 * When the caller is authenticated via session (browser panel), require a
 * valid CSRF token. Password-authenticated API clients are not subject to
 * CSRF because they prove possession of the secret directly.
 */
function require_csrf_if_session() {
    global $body;
    if (empty($_SESSION['auth'])) {
        return;
    }
    $token = $_POST['csrf_token'] ?? ($body['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (empty($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo "Invalid CSRF token";
        exit;
    }
}

function uuid($message = null) {
    if (is_null($message)) {
        return sprintf("%08x-%04x-%04x-%04x-%012x",
            time(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffffffffffff)
        );
    }
    $id = $message["object"]["id"] ?? $message["id"] ?? "";
    return sprintf("%08x", time()) . "-" . hash("sha256", $id);
}

function meta() {
    global $server;
    header('Content-Type: application/xrd+xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0">' . "\n";
    echo '<Link rel="lrdd" template="https://' . $server . '/.well-known/webfinger?resource={uri}"/>' . "\n";
    echo '</XRD>';
    die();
}

function save_log($filename, $message) {
    global $directories;
    $ts   = date('Y-m-d_His');
    $path = $directories['logs'] . "/{$ts}_{$filename}.txt";
    @file_put_contents($path, $message);
}
