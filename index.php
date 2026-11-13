<?php
namespace SUFI;

const VERSION = '1.0.0';

const MEDIA_TYPES = [
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

require __DIR__ . "/sys/config.php";
require __DIR__ . "/sys/database.php";
require __DIR__ . "/sys/delivery.php";
require __DIR__ . "/templates/actor.php";
require __DIR__ . "/templates/webfinger.php";

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// Defaults for config.php files written before these settings existed.
$base_path     = rtrim((string) ($base_path ?? ''), '/');
$base_url      = $base_url ?? "https://{$server}/";
$cookie_secure = $cookie_secure ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$allow_private_fetch  = $allow_private_fetch ?? false;
$outbox_page_size     = (int) ($outbox_page_size ?? 20);
$max_post_chars       = (int) ($max_post_chars ?? 500);
$max_upload_bytes     = (int) ($max_upload_bytes ?? 5 * 1024 * 1024);
$inbox_retention_days = (int) ($inbox_retention_days ?? 90);
$session_lifetime     = (int) ($session_lifetime ?? 43200);
$login_max_attempts   = (int) ($login_max_attempts ?? 5);
$login_window         = (int) ($login_window ?? 900);
$remote_scheme        = $remote_scheme ?? 'https';

security_headers();

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
if (!empty($_SESSION['auth']) && time() - (int) ($_SESSION['seen'] ?? 0) > $session_lifetime) {
    unset($_SESSION['auth']);
}
if (!empty($_SESSION['auth'])) {
    $_SESSION['seen'] = time();
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

    case "/ap/inbox/read":
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { mark_inbox_endpoint(); }
        break;

    case "/ap/inbox/delete":
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { delete_inbox_endpoint(); }
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

    case "/.well-known/nodeinfo":
        nodeinfo_discovery();
        break;

    case "/ap/nodeinfo":
        nodeinfo();
        break;

    case "/ap/people":
        people();
        break;

    case "/ap/profile":
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { update_profile_endpoint(); }
        break;

    case "/ap/upload":
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { upload_media(); }
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
            $limit_key = 'login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

            if (rate_limit_count($limit_key, $login_window) >= $login_max_attempts) {
                http_response_code(429);
                echo 'Too many attempts, try again later';
                die();
            }

            $token = (string) ($_POST['csrf_token'] ?? '');
            $csrf  = !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
            if ($csrf && password_matches($_POST['password'] ?? '')) {
                rate_limit_clear($limit_key);
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                $_SESSION['seen'] = time();
                header('Location: ' . $panel_url);
            } else {
                rate_limit_hit($limit_key);
                header('Location: ' . $panel_url . '?error=1');
            }
            die();
        }

        require __DIR__ . "/templates/panel.php";
        break;

    default:
        if ($request === "/@{$username}" || $request === "/{$username}" || $request === "/") {
            require __DIR__ . "/templates/home.php";
        } elseif (preg_match('#^/tags/([\p{L}\p{N}_-]{1,64})$#u', $request, $m)) {
            tag_page($m[1]);
        } elseif (preg_match('#^/ap/media/([a-f0-9]{32}\.(?:jpg|png|gif|webp))$#', $request, $m)) {
            serve_media($m[1]);
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

    $type      = $body["type"]  ?? null;
    $actor_uri = $body["actor"] ?? null;

    // Blocked actors are dropped before signature verification: no network
    // fetch, no database write, nothing to exploit.
    if (is_string($actor_uri) && $actor_uri !== ''
        && System\Database\get_block($db_handler, $actor_uri)) {
        http_response_code(202);
        die();
    }

    // Every activity — Follow, Undo, Create, Like, anything — must be signed by
    // the actor that claims to have sent it. Verify before touching the database:
    // an unauthenticated inbox is a spam and impersonation vector.
    if (!verifyHTTPSignature()) {
        http_response_code(401);
        die();
    }

    if (!is_string($type) || $type === '' || !is_string($actor_uri) || $actor_uri === '') {
        http_response_code(400);
        die();
    }

    // Account or object deletions: stop delivering to actors that no longer exist.
    if ($type === "Delete") {
        $object     = $body["object"] ?? null;
        $object_uri = is_array($object) ? ($object["id"] ?? null) : (is_string($object) ? $object : null);

        if (is_string($object_uri) && $object_uri !== '') {
            if ($object_uri === $actor_uri) {
                System\Database\remove_follower($db_handler, $actor_uri);
                System\Database\remove_following($db_handler, $actor_uri);
            }
            System\Database\remove_likes_for_object($db_handler, $object_uri);
        }

        http_response_code(202);
        die();
    }

    // Remote actor profile changes: refresh what we stored about them.
    if ($type === "Update") {
        $object = $body["object"] ?? null;
        if (is_array($object) && ($object["id"] ?? null) === $actor_uri) {
            $inbox = $object["endpoints"]["sharedInbox"] ?? $object["inbox"] ?? null;
            if (is_string($inbox) && $inbox !== '') {
                $json = json_encode($object);
                System\Database\refresh_follower($db_handler, $actor_uri, $inbox, $json);
                System\Database\refresh_following($db_handler, $actor_uri, $inbox, $json);
                System\Database\run($db_handler,
                    "UPDATE InboxItem SET actor_handle = ? WHERE actor_uri = ? AND actor_handle = ''",
                    [actor_handle($actor_uri, $object), $actor_uri]
                );
            }
        }
        http_response_code(202);
        die();
    }

    // Account moved to a new actor: carry followers/following over.
    if ($type === "Move") {
        $target = $body["target"] ?? null;
        if (is_string($target) && $target !== '' && $target !== $actor_uri) {
            $doc   = getDataFromURl($target);
            $inbox = is_array($doc) ? ($doc["endpoints"]["sharedInbox"] ?? $doc["inbox"] ?? null) : null;

            if (is_string($inbox) && $inbox !== '') {
                $following = System\Database\get_following_actor($db_handler, $actor_uri);
                if ($following) {
                    System\Database\add_following($db_handler, $target, $inbox, $doc,
                        null, $following["state"] ?? "accepted");
                    System\Database\remove_following($db_handler, $actor_uri);
                }

                $follower = System\Database\get_follower($db_handler, $actor_uri);
                if ($follower) {
                    System\Database\add_follower($db_handler, $target, $inbox, $doc, null);
                    System\Database\remove_follower($db_handler, $actor_uri);
                }
            }
        }
        http_response_code(202);
        die();
    }

    // Responses to Follows we sent: Accept confirms, Reject removes.
    if ($type === "Accept" || $type === "Reject") {
        $object    = $body["object"] ?? null;
        $follow_id = is_array($object) ? ($object["id"] ?? null) : (is_string($object) ? $object : null);

        if (is_string($follow_id) && $follow_id !== ''
            && System\Database\get_following_by_follow_id($db_handler, $follow_id)) {
            if ($type === "Accept") {
                System\Database\set_following_state($db_handler, $follow_id, 'accepted');
            } else {
                System\Database\remove_following_by_follow_id($db_handler, $follow_id);
            }
        }

        http_response_code(202);
        die();
    }

    // Store incoming interactions (all verified at this point)
    if (in_array($type, ["Create", "Announce", "Like"], true)) {
        $obj     = $body["object"] ?? null;
        $obj_uri = is_array($obj) ? ($obj["id"] ?? null) : (is_string($obj) ? $obj : null);

        if ($type === "Like" && is_string($obj_uri) && $obj_uri !== '') {
            System\Database\add_like($db_handler, $obj_uri, $actor_uri);
        }
        System\Database\add_inbox_item(
            $db_handler, $type, $actor_uri, $obj_uri, $body,
            actor_handle($actor_uri, $GLOBALS['sufi_actor'] ?? [])
        );
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

        System\Database\add_follower(
            $db_handler, $actor_uri, $follower_inbox, $actor_data ?? [], $body["id"] ?? null
        );

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

    $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $before = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;
    $unread = System\Database\count_unread_inbox($db_handler);

    $rows = System\Database\get_inbox_items(
        $db_handler, $limit + 1, $before, !empty($_GET['unread'])
    );
    $more = count($rows) > $limit;
    if ($more) { array_pop($rows); }

    $items = [];
    foreach ($rows as $row) {
        $data = json_decode((string) $row['data'], true) ?: [];
        $row['data']     = $data;
        $row['actor_handle'] = ($row['actor_handle'] ?? '') !== ''
            ? $row['actor_handle']
            : actor_handle((string) $row['actor_uri'], []);
        $row['kind']     = inbox_kind((string) $row['type'], $data);
        $row['reply_to'] = inbox_reply_to($data);
        $row['preview']  = inbox_preview($data);
        $items[] = $row;
    }

    $next = ($more && $items) ? (int) $items[count($items) - 1]['id'] : null;

    // Mark after reading, so the panel still sees what was unread.
    if (!empty($_GET['mark_read'])) {
        System\Database\mark_inbox_read($db_handler);
    }

    header('Content-Type: application/json');
    echo json_encode([
        'items'          => $items,
        'next_before_id' => $next,
        'unread'         => $unread,
    ]);
}

function mark_inbox_endpoint() {
    global $db_handler, $body;

    if (!panel_auth()) { http_response_code(401); echo 'Unauthorized'; return; }
    require_csrf_if_session();

    $id = $_POST['id'] ?? ($body['id'] ?? 'all');
    if ($id === 'all' || $id === null || $id === '') {
        System\Database\mark_inbox_read($db_handler);
    } else {
        System\Database\mark_inbox_read($db_handler, (int) $id);
    }

    header('Content-Type: application/json');
    echo json_encode(['unread' => System\Database\count_unread_inbox($db_handler)]);
}

function delete_inbox_endpoint() {
    global $db_handler, $body;

    if (!panel_auth()) { http_response_code(401); echo 'Unauthorized'; return; }
    require_csrf_if_session();

    $id = $_POST['id'] ?? ($body['id'] ?? null);

    if ($id === 'read') {
        System\Database\delete_read_inbox($db_handler);
    } elseif ($id !== null && $id !== '' && $id !== 'all') {
        System\Database\delete_inbox_item($db_handler, (int) $id);
    }

    header('Content-Type: application/json');
    echo json_encode(['unread' => System\Database\count_unread_inbox($db_handler)]);
}

function tag_page($tag) {
    global $db_handler, $base_url;

    $posts = System\Database\posts_with_tag($db_handler, $tag);
    $name  = htmlspecialchars($tag, ENT_QUOTES);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>#' . $name . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;'
        . 'line-height:1.5}a{color:inherit}.post{border-bottom:1px solid #ddd;padding:.75rem 0}'
        . '.meta{color:#777;font-size:.8rem}</style></head><body>'
        . '<p><a href="' . htmlspecialchars($base_url, ENT_QUOTES) . '">back</a></p>'
        . '<h1>#' . $name . '</h1>';

    if (!$posts) {
        echo '<p>No posts use this hashtag.</p>';
    }
    foreach ($posts as $post) {
        echo '<div class="post"><div class="meta">'
            . htmlspecialchars((string) $post['published'], ENT_QUOTES)
            . '</div><div>' . nl2br(htmlspecialchars((string) $post['content'], ENT_QUOTES)) . '</div></div>';
    }

    echo '</body></html>';
}

function inbox_kind($type, array $data) {
    global $base_url;

    if ($type === 'Like')     { return 'like'; }
    if ($type === 'Announce') { return 'boost'; }
    if ($type !== 'Create')   { return 'other'; }

    $object = $data['object'] ?? [];
    if (!is_array($object)) { return 'other'; }

    if (is_string($object['inReplyTo'] ?? null) && $object['inReplyTo'] !== '') {
        return 'reply';
    }

    $tags = $object['tag'] ?? [];
    if (is_array($tags)) {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag['type'] ?? '') === 'Mention'
                && ($tag['href'] ?? '') === "{$base_url}ap/actor") {
                return 'mention';
            }
        }
    }

    return 'post';
}

function inbox_reply_to(array $data) {
    $object = $data['object'] ?? null;
    if (!is_array($object)) { return null; }
    $in_reply_to = $object['inReplyTo'] ?? null;
    return is_string($in_reply_to) && $in_reply_to !== '' ? $in_reply_to : null;
}

function inbox_preview(array $data) {
    $object = $data['object'] ?? null;
    $html   = is_array($object) ? ($object['content'] ?? '') : '';
    if (!is_string($html) || $html === '') { return ''; }

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 300);
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
        'blocked'   => people_list(System\Database\get_blocks($db_handler)),
    ]);
}

function people_list(array $rows) {
    $people = [];

    foreach ($rows as $row) {
        $data = json_decode($row['data'] ?? '{}', true) ?: [];

        $url = $data['url'] ?? ($data['id'] ?? $row['actor_uri']);
        if (!is_string($url)) { $url = $row['actor_uri']; }

        $people[] = [
            'uri'    => $row['actor_uri'],
            'handle' => actor_handle($row['actor_uri'], $data),
            'name'   => is_string($data['name'] ?? null) ? $data['name'] : '',
            'url'    => $url,
            'state'  => $row['state'] ?? 'accepted',
        ];
    }

    return $people;
}

// ════════════════════════════════════════════════════════════════════════════════
// Posts
// ════════════════════════════════════════════════════════════════════════════════

function create_post_endpoint() {
    global $body, $max_post_chars;

    if (!panel_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        return;
    }
    require_csrf_if_session();

    $content  = trim((string) ($_POST['content']     ?? ($body['content']     ?? '')));
    $in_reply = trim((string) ($_POST['in_reply_to'] ?? ($body['in_reply_to'] ?? '')));
    $attachments = requested_attachments();

    if ($attachments === null) {
        http_response_code(400);
        echo "Invalid attachment";
        return;
    }
    if ($content === '' && !$attachments) {
        http_response_code(400);
        echo "Missing content";
        return;
    }
    if (mb_strlen($content) > $max_post_chars) {
        http_response_code(400);
        echo "Post is longer than {$max_post_chars} characters";
        return;
    }
    if ($in_reply !== '' && !preg_match('#^https?://#i', $in_reply)) {
        http_response_code(400);
        echo "Invalid in_reply_to";
        return;
    }

    $activity = create_post($content, $in_reply ?: null, $attachments);
    header('Content-Type: application/activity+json; charset=utf-8');
    echo json_encode($activity);
}

function create_post($content, $in_reply_to = null, array $attachments = []) {
    global $base_url, $db_handler;

    $guid      = uuid();
    $base      = $base_url;
    $published = date('c');
    $tags      = post_tags($content);

    $note = [
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => "{$base}ap/post/{$guid}",
        "type"         => "Note",
        "published"    => $published,
        "attributedTo" => "{$base}ap/actor",
        "content"      => format_content($content, $tags['mentions']),
        "to"           => ["https://www.w3.org/ns/activitystreams#Public"],
        "cc"           => ["{$base}ap/followers"],
    ];
    if ($in_reply_to) { $note["inReplyTo"] = $in_reply_to; }
    if ($tags['tags']) { $note["tag"] = $tags['tags']; }

    if ($attachments) { $note['attachment'] = $attachments; }

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

    $post = System\Database\get_post($db_handler, $guid);
    if (!$post) {
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

    // Remove uploaded files this post was the only user of.
    foreach (local_media_files(json_decode((string) $post['data'], true) ?: []) as $file) {
        $used = (int) $db_handler->query(
            "SELECT COUNT(*) FROM Post WHERE data LIKE " . $db_handler->quote('%' . $file . '%')
        )->fetchColumn();
        if ($used === 0) { @unlink(__DIR__ . '/sys/uploads/' . $file); }
    }

    echo "Deleted";
}

function update_post_endpoint($guid) {
    global $body, $base_url, $db_handler, $max_post_chars;

    if (!panel_auth()) { http_response_code(401); echo "Unauthorized"; return; }
    require_csrf_if_session();

    $content = trim((string) ($_POST['content'] ?? ($body['content'] ?? '')));
    if ($content === '') { http_response_code(400); echo "Missing content"; return; }
    if (mb_strlen($content) > $max_post_chars) {
        http_response_code(400);
        echo "Post is longer than {$max_post_chars} characters";
        return;
    }

    $post = System\Database\get_post($db_handler, $guid);
    if (!$post) { http_response_code(404); echo "Not found"; return; }

    $updated   = date('c');
    $base      = $base_url;
    $actor_url = "{$base}ap/actor";

    $previous = json_decode((string) $post['data'], true) ?: [];
    $previous = $previous['object'] ?? $previous;
    $media    = $previous['attachment'] ?? null;
    $tags     = post_tags($content);

    $note = [
        "@context"     => "https://www.w3.org/ns/activitystreams",
        "id"           => "{$base}ap/post/{$guid}",
        "type"         => "Note",
        "published"    => $post['published'],
        "updated"      => $updated,
        "attributedTo" => $actor_url,
        "content"      => format_content($content, $tags['mentions']),
        "to"           => ["https://www.w3.org/ns/activitystreams#Public"],
        "cc"           => ["{$base}ap/followers"],
    ];
    if (!empty($previous['inReplyTo'])) { $note['inReplyTo'] = $previous['inReplyTo']; }
    if ($tags['tags']) { $note['tag'] = $tags['tags']; }
    if (is_array($media)) { $note['attachment'] = $media; }
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
        // Panel actions by actor URI. Only actors we already know about are
        // accepted, so the panel cannot be used to send signed activities to
        // arbitrary inboxes.
        $known = System\Database\get_following_actor($db_handler, $user)
            ?? System\Database\get_follower($db_handler, $user)
            ?? System\Database\get_block($db_handler, $user);

        if (!$known || $action === "Follow" || empty($known["inbox"])) {
            http_response_code(400);
            echo "Unknown actor";
            return;
        }
        $profileUrl    = $user;
        $profileInbox  = $known["inbox"];
        $profile_data  = json_decode($known["data"] ?? '{}', true) ?: [];
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
        System\Database\add_following(
            $db_handler, $profileUrl, $profileInbox, $profile_data ?? [], $message['id'], 'pending'
        );
    } elseif ($action === "Unfollow") {
        System\Database\remove_following($db_handler, $profileUrl);
    } elseif ($action === "Block") {
        System\Database\add_block($db_handler, $profileUrl, $profileInbox, $profile_data ?? []);
        System\Database\remove_following($db_handler, $profileUrl);
        System\Database\remove_follower($db_handler, $profileUrl);
    } elseif ($action === "Unblock") {
        System\Database\remove_block($db_handler, $profileUrl);
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

    // Servers may send the Follow/Block activity id instead of the object.
    if (is_string($object) && $object !== '') {
        $follower = System\Database\get_follower_by_follow_id($db_handler, $object);
        if ($follower) {
            System\Database\remove_follower($db_handler, $follower['actor_uri']);
        }
        System\Database\remove_following_by_follow_id($db_handler, $object);
        return;
    }

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
// HTTP Signature verification
// ════════════════════════════════════════════════════════════════════════════════

function verifyHTTPSignature() {
    global $input, $body;

    $type      = urlencode($body["type"] ?? "unknown");
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

    // RFC 9421 message signatures (Mastodon 4.3+, some others).
    if (!empty($headers["signature-input"]) && !empty($headers["signature"])) {
        return verify_rfc9421($headers, $actor_uri, $fail);
    }

    // Legacy draft-cavage signature (still the most widely deployed).
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

    if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
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
            $signed .= "(request-target): " . strtolower($_SERVER["REQUEST_METHOD"] ?? "get")
                . " " . $_SERVER["REQUEST_URI"] . "\n";
        } elseif ($h === "host") {
            $signed .= "host: " . strtolower($_SERVER["HTTP_HOST"] ?? "") . "\n";
        } else {
            $signed .= "{$h}: " . ($headers[$h] ?? "") . "\n";
        }
    }

    return verify_with_key(
        rtrim($signed), $parts["signature"], $parts["keyId"], $actor_uri,
        strtolower($parts["algorithm"] ?? "rsa-sha256"), $fail
    );
}

/** RFC 9421: Signature-Input + Signature: label=:base64: */
function verify_rfc9421($headers, $actor_uri, callable $fail) {
    global $input;

    $input_hdr = trim((string) $headers["signature-input"]);
    $sig_hdr   = trim((string) $headers["signature"]);

    if (!preg_match('/^([A-Za-z0-9_-]+)=\(([^)]*)\)(.*)$/s', $input_hdr, $m)) {
        return $fail("MalformedInput", $input_hdr);
    }

    $label      = $m[1];
    $params     = substr($input_hdr, strlen($label) + 1);
    $rest       = $m[3];
    $components = array_values(array_filter(array_map(
        fn($c) => trim($c, ' "'),
        preg_split('/\s+/', trim($m[2])) ?: []
    )));

    $key_id = preg_match('/keyid="([^"]+)"/', $rest, $km) ? $km[1] : '';
    $alg    = preg_match('/alg="([^"]+)"/', $rest, $am) ? $am[1] : 'rsa-v1_5-sha256';
    if ($key_id === '') {
        return $fail("NoKeyId", $input_hdr);
    }

    if (!preg_match('/' . preg_quote($label, '/') . '=:([^:]+):/', $sig_hdr, $sm)) {
        return $fail("MalformedSignature", $sig_hdr);
    }

    if (in_array("content-digest", $components, true)) {
        $digest = (string) ($headers["content-digest"] ?? "");
        if (!preg_match('/sha-256=:([^:]+):/', $digest, $dm)) {
            return $fail("NoContentDigest", $digest);
        }
        if (!hash_equals($dm[1], base64_encode(hash('sha256', $input, true)))) {
            return $fail("ContentDigestFail");
        }
    }

    $lines = [];
    foreach ($components as $name) {
        $value = match ($name) {
            "@method"     => strtoupper($_SERVER["REQUEST_METHOD"] ?? "POST"),
            "@path"       => (string) parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH),
            "@query"      => "?" . (parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_QUERY) ?? ""),
            "@authority"  => (string) ($_SERVER["HTTP_HOST"] ?? ""),
            "@target-uri" => "https://" . ($_SERVER["HTTP_HOST"] ?? "") . ($_SERVER["REQUEST_URI"] ?? "/"),
            "host"        => (string) ($_SERVER["HTTP_HOST"] ?? ""),
            default       => (string) ($headers[$name] ?? ""),
        };
        $lines[] = "\"{$name}\": {$value}";
    }
    $lines[] = "\"@signature-params\": {$params}";

    return verify_with_key(implode("\n", $lines), $sm[1], $key_id, $actor_uri, $alg, $fail);
}

/** Shared key lookup and openssl verification for both signature schemes. */
function verify_with_key($signed, $signature_b64, $key_id, $actor_uri, $algorithm, callable $fail) {
    $key_data   = getDataFromURl(strtok($key_id, '#'));
    $public_key = $key_data["publicKey"]["publicKeyPem"] ?? null;
    if (!$public_key) {
        return $fail("NoPublicKey", $key_id);
    }

    $actor_data = getDataFromURl($actor_uri) ?: [];
    $GLOBALS['sufi_actor'] = $actor_data;
    $actor_pub_key = $actor_data["publicKey"]["publicKeyPem"] ?? null;
    if (!$actor_pub_key || !hash_equals($public_key, $actor_pub_key)) {
        return $fail("KeyMismatch", $key_id);
    }

    $key_host   = strtolower((string) parse_url($key_id, PHP_URL_HOST));
    $actor_host = strtolower((string) parse_url($actor_uri, PHP_URL_HOST));
    if ($key_host === "" || $key_host !== $actor_host) {
        return $fail("HostMismatch", "keyId: {$key_id} actor: {$actor_uri}");
    }

    $openssl_alg = match (strtolower($algorithm)) {
        "rsa-sha512", "rsa-v1_5-sha512", "rsa-pss-sha512" => OPENSSL_ALGO_SHA512,
        default => OPENSSL_ALGO_SHA256,
    };

    $result = openssl_verify($signed, base64_decode($signature_b64), $public_key, $openssl_alg);
    if ($result !== 1) {
        return $fail("Invalid", "openssl: {$result}");
    }

    return true;
}

// ════════════════════════════════════════════════════════════════════════════════
// NodeInfo
// ════════════════════════════════════════════════════════════════════════════════

function nodeinfo_discovery() {
    global $base_url;

    header('Content-Type: application/jrd+json; charset=utf-8');
    echo json_encode(['links' => [[
        'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
        'href' => "{$base_url}ap/nodeinfo",
    ]]]);
}

function nodeinfo() {
    global $db_handler, $user_data;

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'version'           => '2.1',
        'software'          => ['name' => 'sufi', 'version' => VERSION],
        'protocols'         => ['activitypub'],
        'services'          => ['inbound' => [], 'outbound' => []],
        'openRegistrations' => false,
        'usage'             => [
            'users'      => ['total' => 1, 'activeMonth' => 1, 'activeHalfyear' => 1],
            'localPosts' => System\Database\count_posts($db_handler),
        ],
        'metadata'          => ['nodeName' => $user_data['name'] ?? ''],
    ]);
}

// ════════════════════════════════════════════════════════════════════════════════
// Media
// ════════════════════════════════════════════════════════════════════════════════

function serve_media($name) {
    $path = __DIR__ . '/sys/uploads/' . $name;
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!isset(MEDIA_TYPES[$ext]) || !is_file($path)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    header('Content-Type: ' . MEDIA_TYPES[$ext]);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($path);
}

function upload_media() {
    global $base_url, $max_upload_bytes;

    if (!panel_auth()) { http_response_code(401); echo 'Unauthorized'; return; }
    require_csrf_if_session();

    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo 'No file uploaded';
        return;
    }
    if ((int) $file['size'] > $max_upload_bytes) {
        http_response_code(413);
        echo 'File too large';
        return;
    }

    $info = @getimagesize($file['tmp_name']);
    $ext  = match ($info[2] ?? null) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
        default        => null,
    };
    if ($ext === null) {
        http_response_code(400);
        echo 'Only JPEG, PNG, GIF or WebP images are allowed';
        return;
    }

    $dir = __DIR__ . '/sys/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }

    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], "{$dir}/{$name}")) {
        http_response_code(500);
        echo 'Could not store the file';
        return;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'url'       => "{$base_url}ap/media/{$name}",
        'mediaType' => MEDIA_TYPES[$ext],
        'name'      => (string) ($file['name'] ?? ''),
    ]);
}

/**
 * Attachments for a new post: `attachments` as a JSON list of {url, description},
 * or the legacy single `attachment` + `attachment_description`. Null = invalid.
 */
function requested_attachments(): ?array {
    global $body;

    $raw  = $_POST['attachments'] ?? ($body['attachments'] ?? null);
    $list = [];

    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) { return null; }

        foreach ($decoded as $item) {
            $url   = is_array($item) ? ($item['url'] ?? null) : (is_string($item) ? $item : null);
            $media = attachment_from_url($url);
            if (!$media) { return null; }

            $description = is_array($item) ? ($item['description'] ?? '') : '';
            if (is_string($description) && trim($description) !== '') {
                $media['name'] = mb_substr(trim($description), 0, 1500);
            }
            $list[] = $media;
        }
    } else {
        $url         = trim((string) ($_POST['attachment'] ?? ($body['attachment'] ?? '')));
        $description = trim((string) ($_POST['attachment_description'] ?? ($body['attachment_description'] ?? '')));

        if ($url !== '') {
            $media = attachment_from_url($url);
            if (!$media) { return null; }
            if ($description !== '') { $media['name'] = mb_substr($description, 0, 1500); }
            $list[] = $media;
        }
    }

    return count($list) > 4 ? null : $list;
}

/** Local upload filenames referenced by a stored activity. */
function local_media_files(array $data): array {
    $note  = $data['object'] ?? $data;
    $files = [];

    foreach ((array) ($note['attachment'] ?? []) as $attachment) {
        $url = is_array($attachment) ? ($attachment['url'] ?? null) : null;
        if (is_string($url) && preg_match('#/ap/media/([a-f0-9]{32}\.(?:jpg|png|gif|webp))$#', $url, $m)) {
            $files[] = $m[1];
        }
    }

    return array_values(array_unique($files));
}

/** Resolve a local /ap/media/ URL into an ActivityStreams attachment, or null. */
function attachment_from_url($url) {
    global $base_url;

    $prefix = "{$base_url}ap/media/";
    if (!is_string($url) || !str_starts_with($url, $prefix)) { return null; }

    $name = substr($url, strlen($prefix));
    if (!preg_match('#^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$#', $name)) { return null; }
    if (!is_file(__DIR__ . '/sys/uploads/' . $name)) { return null; }

    return [
        'type'      => 'Document',
        'mediaType' => MEDIA_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))],
        'url'       => $url,
    ];
}

// ════════════════════════════════════════════════════════════════════════════════
// Profile
// ════════════════════════════════════════════════════════════════════════════════

function update_profile_endpoint() {
    global $db_handler;

    if (!panel_auth()) { http_response_code(401); echo 'Unauthorized'; return; }
    require_csrf_if_session();

    $name    = trim((string) ($_POST['name'] ?? ''));
    $summary = trim((string) ($_POST['summary'] ?? ''));
    $icon    = trim((string) ($_POST['icon'] ?? ''));

    if ($name === '' || mb_strlen($name) > 100) {
        http_response_code(400);
        echo 'Invalid name';
        return;
    }
    if (mb_strlen($summary) > 2000) {
        http_response_code(400);
        echo 'Summary too long';
        return;
    }
    if ($icon !== '' && !preg_match('#^https?://#i', $icon)) {
        http_response_code(400);
        echo 'Invalid avatar URL';
        return;
    }

    System\Database\update_actor_profile($db_handler, $name, $summary, $icon);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
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

/** Accepts a bcrypt/argon hash from config, or the legacy plain string. */
function password_matches($given) {
    global $password;

    $given = (string) $given;
    $stored = (string) $password;

    if (preg_match('/^\$(2[aby]|argon2(id|i))\$/', $stored)) {
        return password_verify($given, $stored);
    }

    return hash_equals($stored, $given);
}

function security_headers() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}

/**
 * Turn plain text from the composer into the HTML that ActivityStreams expects:
 * escaped, URLs linked, paragraphs and line breaks preserved.
 */
function format_content($text, array $mentions = []) {
    global $base_url;

    $html = htmlspecialchars(trim((string) $text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = preg_replace_callback('~https?://[^\s<]+~i', function ($m) {
        $url    = rtrim($m[0], '.,;:!?)');
        $suffix = substr($m[0], strlen($url));
        return '<a href="' . $url . '" rel="nofollow noopener noreferrer" target="_blank">'
            . $url . '</a>' . $suffix;
    }, $html);

    $html = preg_replace_callback('/(?<![\w@])@([\w.-]+)@([\w-]+(?:\.[\w-]+)+(?::\d+)?)/u',
        function ($m) use ($mentions) {
            $handle = '@' . $m[1] . '@' . $m[2];
            $href   = $mentions[$handle] ?? null;
            return $href
                ? '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="mention">' . $handle . '</a>'
                : $handle;
        }, $html);

    $html = preg_replace_callback('/(?<![\w&#])#([\p{L}\p{N}_]{1,64})/u',
        function ($m) use ($base_url) {
            $slug = mb_strtolower($m[1]);
            return '<a href="' . htmlspecialchars("{$base_url}tags/" . rawurlencode($slug), ENT_QUOTES)
                . '" class="hashtag" rel="tag">#' . $m[1] . '</a>';
        }, $html);

    $paragraphs = preg_split('/\n{2,}/', $html) ?: [$html];
    $paragraphs = array_map(
        fn($p) => '<p>' . str_replace("\n", '<br>', trim($p)) . '</p>',
        array_filter($paragraphs, fn($p) => trim($p) !== '')
    );

    return implode('', $paragraphs);
}

/**
 * Collect mentions and hashtags for a post: resolves handles to actor URLs and
 * builds the ActivityStreams tag list. Unresolvable mentions stay as text.
 */
function post_tags($text) {
    global $base_url, $server;

    $mentions = [];
    $tags     = [];

    if (preg_match_all('/(?<![\w@])@([\w.-]+)@([\w-]+(?:\.[\w-]+)+(?::\d+)?)/u', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $handle = '@' . $match[1] . '@' . $match[2];
            if (isset($mentions[$handle])) { continue; }

            $href = null;
            if (strcasecmp($match[2], $server) === 0) {
                $href = "{$base_url}ap/actor";
            } else {
                $details = getUserDetails($handle);
                $href    = $details['profile'] ?? null;
            }

            if (is_string($href) && $href !== '') {
                $mentions[$handle] = $href;
                $tags[$handle] = ['type' => 'Mention', 'href' => $href, 'name' => $handle];
            }
        }
    }

    if (preg_match_all('/(?<![\w&#])#([\p{L}\p{N}_]{1,64})/u', $text, $hm)) {
        foreach ($hm[1] as $tag) {
            $slug = mb_strtolower($tag);
            $tags['#' . $slug] = [
                'type' => 'Hashtag',
                'href' => "{$base_url}tags/" . rawurlencode($slug),
                'name' => '#' . $tag,
            ];
        }
    }

    return ['mentions' => $mentions, 'tags' => array_values($tags)];
}

function panel_auth() {
    global $password;

    if (!empty($_SESSION['auth'])) {
        return true;
    }
    // API/script access: allow a valid password in the request body.
    return password_matches(request_password());
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

