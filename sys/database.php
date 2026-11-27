<?php
namespace SUFI\System\Database;

function connect_database() {
    try {
        $DBH = new \PDO("sqlite:" . __DIR__ . "/sufi.sqlite");
        $DBH->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $DBH->exec('PRAGMA journal_mode = WAL');
        $DBH->exec('PRAGMA busy_timeout = 5000');
        init_schema($DBH);
        return $DBH;
    } catch (\PDOException $e) {
        die("DB error: " . $e->getMessage());
    }
}

const SCHEMA_VERSION = 5;

function init_schema($DBH) {
    $version = (int) $DBH->query('PRAGMA user_version')->fetchColumn();
    if ($version >= SCHEMA_VERSION) { return; }

    if ($version < 1) {
        apply_sql_file($DBH, __DIR__ . '/sufi.sql');
    }

    if ($version < 3) {
        // v2: inbox read state + actor handle
        add_column($DBH, 'InboxItem', 'actor_handle', "TEXT NOT NULL DEFAULT ''");
        add_column($DBH, 'InboxItem', 'is_read', 'INTEGER NOT NULL DEFAULT 0');
        // v3: follow activity ids, so Accept/Reject and Undo-by-id work
        add_column($DBH, 'Follower', 'follow_id', 'TEXT');
        add_column($DBH, 'Following', 'follow_id', 'TEXT');
        add_column($DBH, 'Following', 'state', "TEXT NOT NULL DEFAULT 'accepted'");
    }

    if ($version < 4) {
        // v4: post visibility + content warning
        add_column($DBH, 'Post', 'visibility', "TEXT NOT NULL DEFAULT 'public'");
        add_column($DBH, 'Post', 'summary', "TEXT NOT NULL DEFAULT ''");
    }

    if ($version < 5) {
        // v5: split directed interactions (inbox) from followed-actor traffic
        // (timeline), and keep the reply target as a queryable column.
        add_column($DBH, 'InboxItem', 'scope', "TEXT NOT NULL DEFAULT 'inbox'");
        add_column($DBH, 'InboxItem', 'parent_uri', "TEXT NOT NULL DEFAULT ''");
        $DBH->exec('CREATE INDEX IF NOT EXISTS InboxItem_scope ON InboxItem (scope, id)');
        $DBH->exec('CREATE INDEX IF NOT EXISTS InboxItem_parent ON InboxItem (parent_uri)');
    }

    $DBH->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
}

function apply_sql_file($DBH, $file) {
    $sql = (string) file_get_contents($file);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        try {
            $DBH->exec($stmt);
        } catch (\PDOException $e) {
            // table already exists
        }
    }
}

function add_column($DBH, $table, $column, $definition) {
    foreach ($DBH->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC) as $existing) {
        if (($existing['name'] ?? '') === $column) { return; }
    }
    $DBH->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function get($DBH, $query, $params = []) {
    $STH = $DBH->prepare($query);
    $STH->execute($params);
    $STH->setFetchMode(\PDO::FETCH_ASSOC);
    return $STH->fetchAll();
}

function run($DBH, $query, $params = []) {
    $STH = $DBH->prepare($query);
    $STH->execute($params);
}

// ── Actor ─────────────────────────────────────────────────────────────────────

function get_user_data($DBH) {
    $STH = $DBH->query("SELECT * FROM Actor LIMIT 1");
    $STH->setFetchMode(\PDO::FETCH_ASSOC);
    return $STH->fetch() ?: [];
}

/**
 * Keep the single Actor row in step with sys/config.php.
 *
 * Creates it on first request, and afterwards keeps identity fields and the
 * public key synced — so a new handle or a rotated private.pem needs no manual
 * SQL. Profile fields (name, summary, icon) are only seeded on creation.
 */
function update_actor_profile($DBH, $name, $summary, $icon) {
    run($DBH, "UPDATE Actor SET name = ?, summary = ?, icon = ? WHERE id = 1",
        [$name, $summary, $icon]);
}

/** How many rows (posts, or the actor's avatar) reference an uploaded file name. */
function media_reference_count($DBH, $name) {
    $like = $DBH->quote('%' . $name . '%');
    return (int) $DBH->query("SELECT COUNT(*) FROM Post WHERE data LIKE {$like}")->fetchColumn()
        + (int) $DBH->query("SELECT COUNT(*) FROM Actor WHERE icon LIKE {$like}")->fetchColumn();
}

function ensure_actor($DBH, $server, $username, $base_url, $private_key, $name, $summary, $icon) {
    $public_key = '';
    if (is_string($private_key) && $private_key !== ''
        && ($key = openssl_pkey_get_private($private_key))) {
        $public_key = openssl_pkey_get_details($key)['key'] ?? '';
    }

    $domain = parse_url("https://{$server}", PHP_URL_HOST) ?: (string) $server;
    $row    = get_user_data($DBH);

    if (!$row) {
        run($DBH,
            "INSERT INTO Actor (type, name, username, summary, domain, base_url, icon, public_key)
             VALUES ('Person', ?, ?, ?, ?, ?, ?, ?)",
            [$name, $username, $summary, $domain, $base_url, $icon, $public_key]
        );
        return;
    }

    $updates = [];
    $params  = [];
    if (($row['username'] ?? '') !== $username) { $updates[] = 'username = ?'; $params[] = $username; }
    if (($row['domain']   ?? '') !== $domain)   { $updates[] = 'domain = ?';   $params[] = $domain; }
    if (($row['base_url'] ?? '') !== $base_url) { $updates[] = 'base_url = ?'; $params[] = $base_url; }
    if ($public_key !== '' && trim((string) ($row['public_key'] ?? '')) !== trim($public_key)) {
        $updates[] = 'public_key = ?';
        $params[]  = $public_key;
    }

    if (!$updates) { return; }

    $params[] = $row['id'];
    run($DBH, 'UPDATE Actor SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);
}

// ── Followers ─────────────────────────────────────────────────────────────────

function get_followers($DBH) {
    return get($DBH, "SELECT * FROM Follower ORDER BY created_at DESC");
}

function get_follower($DBH, $actor_uri) {
    $rows = get($DBH, "SELECT * FROM Follower WHERE actor_uri = ? LIMIT 1", [$actor_uri]);
    return $rows[0] ?? null;
}

function add_follower($DBH, $actor_uri, $inbox, $data, $follow_id = null) {
    run($DBH,
        "INSERT OR REPLACE INTO Follower (actor_uri, inbox, data, follow_id) VALUES (?, ?, ?, ?)",
        [$actor_uri, $inbox, json_encode($data), $follow_id]
    );
}

function get_follower_by_follow_id($DBH, $follow_id) {
    $rows = get($DBH, "SELECT * FROM Follower WHERE follow_id = ? LIMIT 1", [$follow_id]);
    return $rows[0] ?? null;
}

function remove_follower($DBH, $actor_uri) {
    run($DBH, "DELETE FROM Follower WHERE actor_uri = ?", [$actor_uri]);
}

function refresh_follower($DBH, $actor_uri, $inbox, $data) {
    run($DBH,
        "UPDATE Follower SET inbox = ?, data = ? WHERE actor_uri = ?",
        [$inbox, is_string($data) ? $data : json_encode($data), $actor_uri]
    );
}

function remove_followers_by_inbox($DBH, $inbox) {
    $STH = $DBH->prepare("DELETE FROM Follower WHERE inbox = ?");
    $STH->execute([$inbox]);
    return $STH->rowCount();
}

// ── Following ─────────────────────────────────────────────────────────────────

function get_following($DBH) {
    return get($DBH, "SELECT * FROM Following ORDER BY created_at DESC");
}

function count_followers($DBH) {
    return (int) $DBH->query("SELECT COUNT(*) FROM Follower")->fetchColumn();
}

function oldest_follower_id($DBH) {
    return (int) $DBH->query("SELECT COALESCE(MIN(id), 0) FROM Follower")->fetchColumn();
}

function get_followers_page($DBH, $limit, $max_id = null) {
    if ($max_id === null) {
        return get($DBH, "SELECT * FROM Follower ORDER BY id DESC LIMIT ?", [(int) $limit]);
    }
    return get($DBH,
        "SELECT * FROM Follower WHERE id <= ? ORDER BY id DESC LIMIT ?",
        [(int) $max_id, (int) $limit]
    );
}

function count_following($DBH) {
    return (int) $DBH->query("SELECT COUNT(*) FROM Following")->fetchColumn();
}

function oldest_following_id($DBH) {
    return (int) $DBH->query("SELECT COALESCE(MIN(id), 0) FROM Following")->fetchColumn();
}

function get_following_page($DBH, $limit, $max_id = null) {
    if ($max_id === null) {
        return get($DBH, "SELECT * FROM Following ORDER BY id DESC LIMIT ?", [(int) $limit]);
    }
    return get($DBH,
        "SELECT * FROM Following WHERE id <= ? ORDER BY id DESC LIMIT ?",
        [(int) $max_id, (int) $limit]
    );
}

function get_following_actor($DBH, $actor_uri) {
    $rows = get($DBH, "SELECT * FROM Following WHERE actor_uri = ? LIMIT 1", [$actor_uri]);
    return $rows[0] ?? null;
}

function add_following($DBH, $actor_uri, $inbox, $data, $follow_id = null, $state = 'pending') {
    run($DBH,
        "INSERT OR REPLACE INTO Following (actor_uri, inbox, data, follow_id, state) VALUES (?, ?, ?, ?, ?)",
        [$actor_uri, $inbox, json_encode($data), $follow_id, $state]
    );
}

function get_following_by_follow_id($DBH, $follow_id) {
    $rows = get($DBH, "SELECT * FROM Following WHERE follow_id = ? LIMIT 1", [$follow_id]);
    return $rows[0] ?? null;
}

function set_following_state($DBH, $follow_id, $state) {
    run($DBH, "UPDATE Following SET state = ? WHERE follow_id = ?", [$state, $follow_id]);
}

function remove_following_by_follow_id($DBH, $follow_id) {
    run($DBH, "DELETE FROM Following WHERE follow_id = ?", [$follow_id]);
}

function remove_following($DBH, $actor_uri) {
    run($DBH, "DELETE FROM Following WHERE actor_uri = ?", [$actor_uri]);
}

function refresh_following($DBH, $actor_uri, $inbox, $data) {
    run($DBH,
        "UPDATE Following SET inbox = ?, data = ? WHERE actor_uri = ?",
        [$inbox, is_string($data) ? $data : json_encode($data), $actor_uri]
    );
}

// ── Posts ─────────────────────────────────────────────────────────────────────

function get_posts($DBH) {
    return get($DBH, "SELECT * FROM Post ORDER BY published DESC");
}

function count_posts($DBH) {
    return (int) $DBH->query("SELECT COUNT(*) FROM Post")->fetchColumn();
}

function oldest_post_id($DBH) {
    return (int) $DBH->query("SELECT COALESCE(MIN(id), 0) FROM Post")->fetchColumn();
}

function posts_with_tag($DBH, $tag, $limit = 50) {
    return get($DBH,
        "SELECT * FROM Post WHERE content LIKE ? ORDER BY published DESC LIMIT ?",
        ['%#' . $tag . '%', (int) $limit]
    );
}

function get_posts_page($DBH, $limit, $max_id = null) {
    if ($max_id === null) {
        return get($DBH, "SELECT * FROM Post ORDER BY id DESC LIMIT ?", [(int) $limit]);
    }
    return get($DBH,
        "SELECT * FROM Post WHERE id <= ? ORDER BY id DESC LIMIT ?",
        [(int) $max_id, (int) $limit]
    );
}

function get_post($DBH, $guid) {
    $rows = get($DBH, "SELECT * FROM Post WHERE guid = ? LIMIT 1", [$guid]);
    return $rows[0] ?? null;
}

function add_post($DBH, $guid, $type, $content, $published, $in_reply, $data,
    $visibility = 'public', $summary = '') {
    run($DBH,
        "INSERT INTO Post (guid, type, content, published, in_reply, visibility, summary, data)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [$guid, $type, $content, $published, $in_reply, $visibility, $summary, json_encode($data)]
    );
}

// ── Blocks ────────────────────────────────────────────────────────────────────

function get_blocks($DBH) {
    return get($DBH, "SELECT * FROM Block ORDER BY created_at DESC");
}

function get_block($DBH, $actor_uri) {
    $rows = get($DBH, "SELECT * FROM Block WHERE actor_uri = ? LIMIT 1", [$actor_uri]);
    return $rows[0] ?? null;
}

function add_block($DBH, $actor_uri, $inbox, $data) {
    run($DBH,
        "INSERT OR REPLACE INTO Block (actor_uri, inbox, data) VALUES (?, ?, ?)",
        [$actor_uri, $inbox, is_string($data) ? $data : json_encode($data)]
    );
}

function remove_block($DBH, $actor_uri) {
    run($DBH, "DELETE FROM Block WHERE actor_uri = ?", [$actor_uri]);
}

// ── Delivery queue ────────────────────────────────────────────────────────────

function enqueue_delivery($DBH, $inbox, $data) {
    run($DBH,
        "INSERT INTO Delivery (inbox, payload) VALUES (?, ?)",
        [$inbox, json_encode($data)]
    );
    return (int) $DBH->lastInsertId();
}

function claim_deliveries($DBH, $limit = 25) {
    return get($DBH,
        "SELECT * FROM Delivery WHERE next_try <= datetime('now') ORDER BY id LIMIT ?",
        [(int) $limit]
    );
}

function retry_delivery($DBH, $id, $attempts, $next_try) {
    run($DBH,
        "UPDATE Delivery SET attempts = ?, next_try = ? WHERE id = ?",
        [$attempts, $next_try, $id]
    );
}

function delete_delivery($DBH, $id) {
    run($DBH, "DELETE FROM Delivery WHERE id = ?", [$id]);
}

function count_deliveries($DBH) {
    return (int) $DBH->query("SELECT COUNT(*) FROM Delivery")->fetchColumn();
}

// ── Likes ─────────────────────────────────────────────────────────────────────

function get_likes($DBH) {
    return get($DBH, "SELECT * FROM Like ORDER BY created_at DESC");
}

function add_like($DBH, $object_uri, $actor_uri) {
    run($DBH,
        "INSERT OR IGNORE INTO Like (object_uri, actor_uri) VALUES (?, ?)",
        [$object_uri, $actor_uri]
    );
}

function remove_like($DBH, $object_uri, $actor_uri) {
    run($DBH,
        "DELETE FROM Like WHERE object_uri = ? AND actor_uri = ?",
        [$object_uri, $actor_uri]
    );
}

function remove_likes_for_object($DBH, $object_uri) {
    run($DBH, "DELETE FROM Like WHERE object_uri = ?", [$object_uri]);
}

// ── Posts (extended) ──────────────────────────────────────────────────────────

function delete_post($DBH, $guid) {
    run($DBH, "DELETE FROM Post WHERE guid = ?", [$guid]);
}

function update_post($DBH, $guid, $content, $data, $visibility = 'public', $summary = '') {
    run($DBH,
        "UPDATE Post SET content = ?, data = ?, visibility = ?, summary = ? WHERE guid = ?",
        [$content, json_encode($data), $visibility, $summary, $guid]
    );
}

// ── Inbox items ───────────────────────────────────────────────────────────────

function get_inbox_items($DBH, $limit = 50, $before_id = null, $unread_only = false, $scope = 'inbox') {
    $where  = ['scope = ?'];
    $params = [$scope];

    if ($unread_only) { $where[] = 'is_read = 0'; }
    if ($before_id !== null) {
        $where[]  = 'id < ?';
        $params[] = (int) $before_id;
    }
    $params[] = (int) $limit;

    $sql = 'SELECT * FROM InboxItem'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY id DESC LIMIT ?';

    return get($DBH, $sql, $params);
}

function get_inbox_item($DBH, $id) {
    $rows = get($DBH, "SELECT * FROM InboxItem WHERE id = ? LIMIT 1", [(int) $id]);
    return $rows[0] ?? null;
}

function get_inbox_item_by_object($DBH, $object_uri) {
    if ((string) $object_uri === '') { return null; }
    $rows = get($DBH, "SELECT * FROM InboxItem WHERE object_uri = ? ORDER BY id LIMIT 1", [$object_uri]);
    return $rows[0] ?? null;
}

function add_inbox_item($DBH, $type, $actor_uri, $object_uri, $data, $actor_handle = '', $scope = 'inbox', $parent_uri = '') {
    run($DBH,
        "INSERT INTO InboxItem (type, actor_uri, actor_handle, object_uri, data, scope, parent_uri)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$type, $actor_uri, $actor_handle, $object_uri, json_encode($data), $scope, (string) $parent_uri]
    );
}

/**
 * Everything below a directed item is part of that thread: promote stored
 * children to the inbox, following the chain so out-of-order delivery
 * still ends up whole. Returns the number of rows moved.
 */
function promote_inbox_thread($DBH, $object_uri) {
    $pending = [(string) $object_uri];
    $seen    = [];
    $moved   = 0;

    while ($pending) {
        $uri = array_pop($pending);
        if ($uri === '' || isset($seen[$uri])) { continue; }
        $seen[$uri] = true;

        $children = get($DBH,
            "SELECT object_uri FROM InboxItem WHERE scope <> 'inbox' AND parent_uri = ?", [$uri]);
        if (!$children) { continue; }

        run($DBH, "UPDATE InboxItem SET scope = 'inbox' WHERE scope <> 'inbox' AND parent_uri = ?", [$uri]);
        $moved += count($children);
        foreach ($children as $child) { $pending[] = (string) $child['object_uri']; }
    }

    return $moved;
}

function inbox_parent_uri(array $activity) {
    $object = $activity['object'] ?? null;
    if (!is_array($object)) { return ''; }
    $parent = $object['inReplyTo'] ?? null;
    if (is_array($parent)) { $parent = $parent['id'] ?? null; }
    return is_string($parent) ? $parent : '';
}

/**
 * Decide what a verified incoming activity is to this single-user server:
 *   ['inbox', $parent_uri]    aimed at us — mention, reply to our post, a
 *                             reply in a thread we host, like/boost of ours
 *   ['timeline', $parent_uri] traffic from people we follow or threads we
 *                             already carry
 *   ['drop', $parent_uri]     neither: do not store it at all
 */
function inbox_classify($DBH, array $activity, string $base_url, ?callable $parent_scope = null) {
    $type    = (string) ($activity['type'] ?? '');
    $actor   = (string) ($activity['actor'] ?? '');
    $object  = $activity['object'] ?? null;
    $obj_uri = is_array($object) ? (string) ($object['id'] ?? '') : (is_string($object) ? $object : '');
    $parent  = inbox_parent_uri($activity);

    $actor_uri = $base_url . 'ap/actor';
    $local     = fn(string $uri): bool => $uri !== '' && str_starts_with($uri, $base_url);

    if ($type === 'Like' || $type === 'Announce') {
        if ($local($obj_uri)) { return ['inbox', '']; }
        if ($type === 'Announce' && $actor !== '' && get_following_actor($DBH, $actor)) {
            return ['timeline', ''];
        }
        return ['drop', ''];
    }

    if ($type !== 'Create' || !is_array($object)) { return ['drop', $parent]; }

    $tags      = is_array($object['tag'] ?? null) ? $object['tag'] : [];
    $mentioned = false;
    $followed  = false;
    $addressed = false;

    foreach ($tags as $tag) {
        if (!is_array($tag) || ($tag['type'] ?? '') !== 'Mention') { continue; }
        $href = (string) ($tag['href'] ?? '');
        if ($href === $actor_uri) { $mentioned = true; }
        if ($href !== '' && get_following_actor($DBH, $href)) { $followed = true; }
    }

    foreach (['to', 'cc'] as $field) {
        $audience = $object[$field] ?? null;
        foreach (is_array($audience) ? $audience : [] as $target) {
            if (!is_string($target)) { continue; }
            if ($target === $actor_uri || $target === $base_url . 'ap/followers') { $addressed = true; }
        }
    }

    if ($mentioned || $addressed || ($parent !== '' && $local($parent))) {
        return ['inbox', $parent];
    }

    // A reply to something we already keep directed is part of our thread.
    if ($parent !== '') {
        $parent_scope_value = $parent_scope
            ? $parent_scope($parent)
            : (function () use ($DBH, $parent): ?string {
                $row = get_inbox_item_by_object($DBH, $parent);
                return $row ? (string) ($row['scope'] ?? '') : null;
            })();
        if ($parent_scope_value === 'inbox') { return ['inbox', $parent]; }
    }

    // Timeline: the author is someone we follow, the reply answers someone we
    // follow, or the parent is a post we already carry. Anything with a reply
    // target is kept even when the parent is unknown yet — it may be an
    // out-of-order member of a thread we host, and promote_inbox_thread()
    // fixes the scope once the parent lands.
    if ($parent !== '' || $followed || ($actor !== '' && get_following_actor($DBH, $actor))) {
        return ['timeline', $parent];
    }

    return ['drop', $parent];
}

function mark_inbox_read($DBH, $id = null) {
    if ($id === null) {
        run($DBH, "UPDATE InboxItem SET is_read = 1 WHERE is_read = 0 AND scope = 'inbox'");
        return;
    }
    run($DBH, "UPDATE InboxItem SET is_read = 1 WHERE id = ?", [(int) $id]);
}

function count_unread_inbox($DBH) {
    return (int) $DBH->query("SELECT COUNT(*) FROM InboxItem WHERE is_read = 0 AND scope = 'inbox'")->fetchColumn();
}

function delete_inbox_item($DBH, $id) {
    run($DBH, "DELETE FROM InboxItem WHERE id = ?", [(int) $id]);
}

function delete_read_inbox($DBH) {
    $STH = $DBH->prepare("DELETE FROM InboxItem WHERE is_read = 1 AND scope = 'inbox'");
    $STH->execute();
    return $STH->rowCount();
}

function prune_inbox($DBH, $days) {
    $days = max(1, (int) $days);
    run($DBH, "DELETE FROM InboxItem WHERE created_at < datetime('now', ?)", ["-{$days} days"]);
    return $DBH->query("SELECT changes()")->fetchColumn();
}
