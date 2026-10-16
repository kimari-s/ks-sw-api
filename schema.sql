-- ks-sw-api SQLite schema, version 1. Implementation-internal: not part of
-- the kssw protocol (which defines only HTTP behaviour and JSON).
--
-- The PHP implementation (php/) runs this file; php/api/lib/schema.sql is
-- its copy (`make check-schema`). A Python implementation is parked; any
-- implementation sharing a database must use this file unchanged. It runs on
-- first access, so every statement is idempotent.
--
-- Portable to old SQLite (3.7.x, as on some shared hosts): no window
-- functions, no UPSERT, no JSON1, no WITHOUT ROWID, no partial indexes.
--
-- Conventions:
-- - ids: lowercase UUIDv4 TEXT.
-- - times: fixed-width UTC TEXT "YYYY-MM-DDTHH:MM:SS.ffffffZ" (27 chars), so
--   ordering and ranges are plain string comparisons.
-- - index names: idx_<table>_<columns>.
-- - ip_hmac / client_id_hmac: HMAC-SHA256 (hex, first 32 chars) keyed by
--   meta('secret'); raw IPs and raw client ids are never stored.

CREATE TABLE IF NOT EXISTS meta (
    name  TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS posts (
    id             TEXT PRIMARY KEY,
    text           TEXT NOT NULL,
    parent_id      TEXT NULL REFERENCES posts (id),
    root_id        TEXT NOT NULL,
    author         TEXT NULL,
    client_id_hmac TEXT NULL,
    ip_hmac        TEXT NULL,
    status         TEXT NOT NULL DEFAULT 'active',
    created_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_posts_created_at_id             ON posts (created_at, id);
CREATE INDEX IF NOT EXISTS idx_posts_root_id_created_at        ON posts (root_id, created_at);
CREATE INDEX IF NOT EXISTS idx_posts_parent_id                 ON posts (parent_id);
CREATE INDEX IF NOT EXISTS idx_posts_author_created_at         ON posts (author, created_at);
CREATE INDEX IF NOT EXISTS idx_posts_client_id_hmac_created_at ON posts (client_id_hmac, created_at);
CREATE INDEX IF NOT EXISTS idx_posts_ip_hmac_created_at        ON posts (ip_hmac, created_at);

-- A tag is identified by its text (the API's Tag.name).
CREATE TABLE IF NOT EXISTS tags (
    name       TEXT PRIMARY KEY,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS post_tags (
    tag_name TEXT NOT NULL REFERENCES tags (name),
    post_id  TEXT NOT NULL REFERENCES posts (id),
    PRIMARY KEY (tag_name, post_id)
);
CREATE INDEX IF NOT EXISTS idx_post_tags_post_id ON post_tags (post_id);

-- Presence heartbeats (POST /v1/presence), only for GET /v1/active_count;
-- rows older than an hour are deleted.
CREATE TABLE IF NOT EXISTS presence (
    ip_hmac TEXT NOT NULL,
    seen_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_presence_seen_at_ip_hmac ON presence (seen_at, ip_hmac);

-- meta rows:
-- - 'schema_version': set here on every install.
-- - 'secret': the HMAC key, 32 random bytes as lowercase hex. Each
--   implementation creates it after running this file, from the OS CSPRNG,
--   with INSERT OR IGNORE so the first process to get there wins a race.
INSERT OR REPLACE INTO meta (name, value) VALUES ('schema_version', '1');
