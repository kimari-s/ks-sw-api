-- Tables of the PHP admin API and page (lib/admin.php). Implementation-
-- specific: not part of ../schema.sql or the kssw protocol.
-- Idempotent; run when meta.admin_schema is not current. Same conventions
-- as schema.sql.

-- What an admin did: delete, restore, maintenance_on, maintenance_off,
-- backup (API and page), login, logout (page). One row per post for bulk
-- deletes. result: changed | unchanged | not_found | invalid_id | ok.
-- via: api | html.
CREATE TABLE IF NOT EXISTS admin_log (
    id         INTEGER PRIMARY KEY,
    created_at TEXT NOT NULL,
    action     TEXT NOT NULL,
    target     TEXT NULL,
    dry_run    INTEGER NOT NULL DEFAULT 0,
    result     TEXT NULL,
    via        TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_admin_log_created_at ON admin_log (created_at);

-- Failed admin authentications (API tokens and page logins), by keyed hash
-- of the client IP, for the rate limit.
CREATE TABLE IF NOT EXISTS admin_login_failures (
    ip_hmac    TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_admin_login_failures_ip_hmac_created_at ON admin_login_failures (ip_hmac, created_at);
