CREATE TABLE IF NOT EXISTS Actor (
    id         INTEGER PRIMARY KEY,
    type       TEXT NOT NULL DEFAULT 'Person',
    name       TEXT NOT NULL,
    username   TEXT NOT NULL,
    summary    TEXT DEFAULT '',
    domain     TEXT NOT NULL,
    base_url   TEXT NOT NULL,
    icon       TEXT DEFAULT '',
    public_key TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS Follower (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_uri  TEXT NOT NULL UNIQUE,
    inbox      TEXT NOT NULL,
    data       TEXT NOT NULL DEFAULT '{}',
    follow_id  TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS Following (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_uri  TEXT NOT NULL UNIQUE,
    inbox      TEXT NOT NULL,
    data       TEXT NOT NULL DEFAULT '{}',
    follow_id  TEXT,
    state      TEXT NOT NULL DEFAULT 'accepted',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS Post (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    guid       TEXT NOT NULL UNIQUE,
    type       TEXT NOT NULL DEFAULT 'Note',
    content    TEXT NOT NULL,
    published  TEXT NOT NULL DEFAULT (datetime('now')),
    in_reply   TEXT DEFAULT NULL,
    visibility TEXT NOT NULL DEFAULT 'public',
    summary    TEXT NOT NULL DEFAULT '',
    data       TEXT NOT NULL DEFAULT '{}'
);

CREATE TABLE IF NOT EXISTS Like (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    object_uri TEXT NOT NULL,
    actor_uri  TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(object_uri, actor_uri)
);

CREATE TABLE IF NOT EXISTS InboxItem (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    type         TEXT NOT NULL,
    actor_uri    TEXT NOT NULL,
    actor_handle TEXT NOT NULL DEFAULT '',
    object_uri   TEXT,
    data         TEXT NOT NULL DEFAULT '{}',
    is_read      INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS InboxItem_created ON InboxItem (created_at);

CREATE TABLE IF NOT EXISTS Block (
    actor_uri  TEXT PRIMARY KEY,
    inbox      TEXT NOT NULL DEFAULT '',
    data       TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS Delivery (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    inbox      TEXT NOT NULL,
    payload    TEXT NOT NULL,
    attempts   INTEGER NOT NULL DEFAULT 0,
    next_try   TEXT NOT NULL DEFAULT (datetime('now')),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS Delivery_next_try ON Delivery (next_try);
