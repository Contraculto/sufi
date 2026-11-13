<?php
namespace SUFI\System\Database;

function connect_database() {
    try {
        $DBH = new \PDO("sqlite:" . __DIR__ . "/sufi.sqlite");
        $DBH->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        init_schema($DBH);
        return $DBH;
    } catch (\PDOException $e) {
        die("DB error: " . $e->getMessage());
    }
}

function init_schema($DBH) {
    $sql = file_get_contents(__DIR__ . '/sufi.sql');
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        try { $DBH->exec($stmt); } catch (\PDOException $e) { /* already exists */ }
    }
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

function add_follower($DBH, $actor_uri, $inbox, $data) {
    run($DBH,
        "INSERT OR REPLACE INTO Follower (actor_uri, inbox, data) VALUES (?, ?, ?)",
        [$actor_uri, $inbox, json_encode($data)]
    );
}

function remove_follower($DBH, $actor_uri) {
    run($DBH, "DELETE FROM Follower WHERE actor_uri = ?", [$actor_uri]);
}

// ── Following ─────────────────────────────────────────────────────────────────

function get_following($DBH) {
    return get($DBH, "SELECT * FROM Following ORDER BY created_at DESC");
}

function add_following($DBH, $actor_uri, $inbox, $data) {
    run($DBH,
        "INSERT OR REPLACE INTO Following (actor_uri, inbox, data) VALUES (?, ?, ?)",
        [$actor_uri, $inbox, json_encode($data)]
    );
}

function remove_following($DBH, $actor_uri) {
    run($DBH, "DELETE FROM Following WHERE actor_uri = ?", [$actor_uri]);
}

// ── Posts ─────────────────────────────────────────────────────────────────────

function get_posts($DBH) {
    return get($DBH, "SELECT * FROM Post ORDER BY published DESC");
}

function get_post($DBH, $guid) {
    $rows = get($DBH, "SELECT * FROM Post WHERE guid = ? LIMIT 1", [$guid]);
    return $rows[0] ?? null;
}

function add_post($DBH, $guid, $type, $content, $published, $in_reply, $data) {
    run($DBH,
        "INSERT INTO Post (guid, type, content, published, in_reply, data) VALUES (?, ?, ?, ?, ?, ?)",
        [$guid, $type, $content, $published, $in_reply, json_encode($data)]
    );
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

// ── Posts (extended) ──────────────────────────────────────────────────────────

function delete_post($DBH, $guid) {
    run($DBH, "DELETE FROM Post WHERE guid = ?", [$guid]);
}

function update_post($DBH, $guid, $content, $data) {
    run($DBH,
        "UPDATE Post SET content = ?, data = ? WHERE guid = ?",
        [$content, json_encode($data), $guid]
    );
}

// ── Inbox items ───────────────────────────────────────────────────────────────

function get_inbox_items($DBH, $limit = 50) {
    return get($DBH,
        "SELECT * FROM InboxItem ORDER BY created_at DESC LIMIT ?",
        [$limit]
    );
}

function add_inbox_item($DBH, $type, $actor_uri, $object_uri, $data) {
    run($DBH,
        "INSERT INTO InboxItem (type, actor_uri, object_uri, data) VALUES (?, ?, ?, ?)",
        [$type, $actor_uri, $object_uri, json_encode($data)]
    );
}
