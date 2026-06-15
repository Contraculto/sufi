<?php
namespace SUFI;

require __DIR__ . "/sys/config.php";
require __DIR__ . "/sys/database.php";
require __DIR__ . "/templates/actor.php";
require __DIR__ . "/templates/webfinger.php";

// ── Bootstrap ─────────────────────────────────────────────────────────────────
if (!file_exists(__DIR__ . '/sys/private.pem')) {
    http_response_code(500);
    die('Missing RSA private key. Generate it with: openssl genrsa -out sys/private.pem 2048');
}

$db_handler = System\Database\connect_database();
$user_data  = System\Database\get_user_data($db_handler);

session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// ── Router ────────────────────────────────────────────────────────────────────
$request = strtok($_SERVER["REQUEST_URI"], '?');

// Strip subdirectory prefix when SUFI is deployed inside an existing site
if ($base_path && str_starts_with($request, $base_path)) {
    $request = substr($request, strlen($base_path)) ?: '/';
}

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
    case "/sufi/":
        if (!$panel_enabled) { http_response_code(404); echo "Not found"; break; }
        require __DIR__ . "/templates/panel.php";
        break;

    case "/sufi/login":
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['password'] ?? '') === $password) {
            $_SESSION['auth'] = true;
        }
        header('Location: /sufi/');
        die();

    case "/sufi/logout":
        session_destroy();
        header('Location: /sufi/');
        die();

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
    global $body, $server, $db_handler;

    $type = $body["type"] ?? null;

    // Store incoming interactions
    if (in_array($type, ["Create", "Announce", "Like"])) {
        $obj     = $body["object"] ?? null;
        $obj_uri = is_array($obj) ? ($obj["id"] ?? null) : (is_string($obj) ? $obj : null);
        System\Database\add_inbox_item($db_handler, $type, $body["actor"] ?? "", $obj_uri, $body);
        http_response_code(202);
        die();
    }

    // Silently accept and ignore the rest
    if (in_array($type, ["Add", "Remove", "Reject", "Update", "Delete", "Move"])) {
        http_response_code(202);
        die();
    }

    if ($type === "Follow") {
        if (!verifyHTTPSignature()) {
            http_response_code(401);
            die();
        }

        $follower_actor = $body["actor"];
        $actor_data     = getDataFromURl($follower_actor);
        $follower_inbox = $actor_data["endpoints"]["sharedInbox"]
                       ?? $actor_data["inbox"]
                       ?? null;

        if (!$follower_inbox) {
            http_response_code(400);
            die();
        }

        System\Database\add_follower($db_handler, $follower_actor, $follower_inbox, $actor_data ?? []);

        $guid    = uuid();
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "https://{$server}/{$guid}",
            "type"     => "Accept",
            "actor"    => "https://{$server}/ap/actor",
            "object"   => [
                "@context" => "https://www.w3.org/ns/activitystreams",
                "id"       => $body["id"],
                "type"     => "Follow",
                "actor"    => $follower_actor,
                "object"   => "https://{$server}/ap/actor",
            ],
        ];
        sendMessageToSingle($follower_inbox, $message);
        save_log("Follow.Accept", "actor: {$follower_actor}");
        http_response_code(202);
        die();
    }

    if ($type === "Undo") {
        $actor_uri = $body["actor"] ?? null;
        if (!System\Database\get_follower($db_handler, $actor_uri)) {
            http_response_code(202);
            die();
        }
        if (!verifyHTTPSignature()) {
            http_response_code(401);
            die();
        }
        undo($body);
        http_response_code(202);
        die();
    }

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
    global $server, $db_handler;

    $guid      = uuid();
    $base      = "https://{$server}/";
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
    header('Content-Type: application/activity+json; charset=utf-8');
    echo $post['data'];
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
    global $db_handler, $server;

    if (!panel_auth()) { http_response_code(401); echo "Unauthorized"; return; }
    require_csrf_if_session();

    if (!System\Database\get_post($db_handler, $guid)) {
        http_response_code(404); echo "Not found"; return;
    }

    $activity = [
        "@context" => "https://www.w3.org/ns/activitystreams",
        "id"       => "https://{$server}/ap/activity/" . uuid(),
        "type"     => "Delete",
        "actor"    => "https://{$server}/ap/actor",
        "to"       => ["https://www.w3.org/ns/activitystreams#Public"],
        "object"   => "https://{$server}/ap/post/{$guid}",
    ];
    sendToFollowers($activity);
    System\Database\delete_post($db_handler, $guid);
    echo "Deleted";
}

function update_post_endpoint($guid) {
    global $body, $db_handler, $server;

    if (!panel_auth()) { http_response_code(401); echo "Unauthorized"; return; }
    require_csrf_if_session();

    $content = $_POST['content'] ?? ($body['content'] ?? null);
    if (!$content) { http_response_code(400); echo "Missing content"; return; }

    $post = System\Database\get_post($db_handler, $guid);
    if (!$post) { http_response_code(404); echo "Not found"; return; }

    $updated   = date('c');
    $base      = "https://{$server}/";
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
    global $server, $db_handler;

    if (!panel_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        return;
    }
    require_csrf_if_session();

    $user   = $_POST["user"]   ?? null;
    $action = $_POST["action"] ?? null;

    if (!in_array($action, ["Follow", "Unfollow", "Block", "Unblock"])) {
        http_response_code(400);
        echo "Unsupported action";
        return;
    }

    $details      = getUserDetails($user);
    $profileInbox = $details["inbox"];
    $profileUrl   = $details["profile"];
    $guid         = uuid();
    $actor_url    = "https://{$server}/ap/actor";

    if ($action === "Follow") {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "https://{$server}/{$guid}",
            "type"     => "Follow",
            "actor"    => $actor_url,
            "object"   => $profileUrl,
        ];
    } elseif ($action === "Unfollow") {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "https://{$server}/{$guid}",
            "type"     => "Undo",
            "actor"    => $actor_url,
            "object"   => ["type" => "Follow", "actor" => $actor_url, "object" => $profileUrl],
        ];
    } elseif ($action === "Block") {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "https://{$server}/{$guid}",
            "type"     => "Block",
            "actor"    => $actor_url,
            "object"   => $profileUrl,
            "to"       => $profileUrl,
        ];
    } else {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "https://{$server}/{$guid}",
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
        global $server;
        $actor_url = urlencode("https://{$server}/ap/actor");
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
    $actor       = is_array($object) ? ($object["actor"] ?? $message["actor"]) : $message["actor"];

    if ($object_type === "Follow") {
        System\Database\remove_follower($db_handler, $actor);
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// HTTP signing and sending
// ════════════════════════════════════════════════════════════════════════════════

function generate_signed_headers($message, $host, $path, $method) {
    global $server, $key_private;

    $keyId  = "https://{$server}/ap/actor#main-key";
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
    ];
}

function sendMessageToSingle($inbox, $message) {
    $parsed  = parse_url($inbox);
    $host    = $parsed['host'];
    $path    = $parsed['path'] . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    $headers = generate_signed_headers($message, $host, $path, 'POST');
    $json    = json_encode($message);

    $ctx = stream_context_create(['http' => [
        'method'         => 'POST',
        'header'         => implode("\r\n", $headers),
        'content'        => $json,
        'ignore_errors'  => true,
        'timeout'        => 10,
    ]]);
    @file_get_contents($inbox, false, $ctx);
}

function sendToFollowers($message) {
    global $db_handler;
    foreach (System\Database\get_followers($db_handler) as $follower) {
        sendMessageToSingle($follower['inbox'], $message);
    }
}

function getDataFromURl($url) {
    $parsed  = parse_url($url);
    $host    = $parsed['host'];
    $path    = $parsed['path'] . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    $headers = generate_signed_headers(null, $host, $path, 'GET');

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout'       => 10,
    ]]);
    $response = @file_get_contents($url, false, $ctx);
    return $response ? json_decode($response, true) : null;
}

function getUserDetails($handle) {
    $parts = explode("@", ltrim($handle, "@"));
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
    global $input, $body, $server, $directories;

    $type    = urlencode($body["type"] ?? "unknown");
    $headers = array_change_key_case(get_request_headers(), CASE_LOWER);

    if (!isset($headers["date"])) {
        save_log("{$type}.Signature.NoDate", print_r($headers, true));
        return null;
    }

    $headerDatetime  = \DateTime::createFromFormat('D, d M Y H:i:s T', $headers["date"]);
    $currentDatetime = new \DateTime();

    if (abs($currentDatetime->getTimestamp() - $headerDatetime->getTimestamp()) > 3600) {
        save_log("{$type}.Signature.DateDrift", "header: " . $headers["date"]);
        return false;
    }

    if (isset($body["published"])) {
        $publishedDatetime = new \DateTime($body["published"]);
        if (abs($publishedDatetime->getTimestamp() - $headerDatetime->getTimestamp()) > 120) {
            save_log("{$type}.Signature.PublishedSkew", "published: " . $body["published"]);
            return false;
        }
    }

    if (isset($headers["digest"])) {
        [$digestAlgorithm, $digestHash] = explode("=", $headers["digest"], 2);
        $algo = match(strtoupper($digestAlgorithm)) {
            "SHA-256", "HS2019" => "sha256",
            "SHA-512"           => "sha512",
            default             => strtolower($digestAlgorithm),
        };
        if (base64_encode(hash($algo, $input, true)) !== $digestHash) {
            save_log("{$type}.Signature.DigestFail", "expected: " . base64_encode(hash($algo, $input, true)));
            return false;
        }
    }

    $sig_header = $headers["signature"] ?? null;
    if (!$sig_header) { return false; }

    preg_match_all('/(\w+)="([^"]+)"/', $sig_header, $m);
    $parts = array_combine($m[1], $m[2]);

    $signed = "";
    foreach (explode(" ", $parts["headers"]) as $h) {
        if ($h === "(request-target)") {
            $method = strtolower($_SERVER["REQUEST_METHOD"]);
            $signed .= "(request-target): {$method} " . $_SERVER["REQUEST_URI"] . "\n";
        } elseif ($h === "host") {
            $signed .= "host: " . strtolower($_SERVER["HTTP_HOST"]) . "\n";
        } else {
            $signed .= "{$h}: " . ($headers[$h] ?? "") . "\n";
        }
    }
    $signed = rtrim($signed);

    $key_data   = getDataFromURl($parts["keyId"]);
    $public_key = $key_data["publicKey"]["publicKeyPem"] ?? null;
    if (!$public_key) { return false; }

    $actor_data    = getDataFromURl($body["actor"]);
    $actor_pub_key = $actor_data["publicKey"]["publicKeyPem"] ?? null;
    if ($public_key !== $actor_pub_key) {
        save_log("{$type}.Signature.KeyMismatch", "keyId: " . $parts["keyId"]);
        return false;
    }

    $algorithm = match($parts["algorithm"] ?? "rsa-sha256") {
        "hs2019"     => "sha256",
        "rsa-sha512" => "sha512",
        default      => "sha256",
    };

    $result = openssl_verify($signed, base64_decode($parts["signature"]), $public_key, $algorithm);
    save_log("{$type}.Signature." . json_encode($result === 1), "actor: " . ($body["actor"] ?? ""));
    return $result === 1;
}

// ════════════════════════════════════════════════════════════════════════════════
// Utilities
// ════════════════════════════════════════════════════════════════════════════════

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
