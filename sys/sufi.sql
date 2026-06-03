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
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS Following (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_uri  TEXT NOT NULL UNIQUE,
    inbox      TEXT NOT NULL,
    data       TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS Post (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    guid       TEXT NOT NULL UNIQUE,
    type       TEXT NOT NULL DEFAULT 'Note',
    content    TEXT NOT NULL,
    published  TEXT NOT NULL DEFAULT (datetime('now')),
    in_reply   TEXT DEFAULT NULL,
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
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type       TEXT NOT NULL,
    actor_uri  TEXT NOT NULL,
    object_uri TEXT,
    data       TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
