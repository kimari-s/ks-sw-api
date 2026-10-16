<?php
// ks-sw-api settings. Copy to config.php (next to index.php) and edit.
// Every key is optional; the values below are the defaults.
// .htaccess denies direct access to config.php.

return [
    // SQLite file. Keep it outside the document root when you can,
    // e.g. dirname(__DIR__, 3) . '/kssw/kssw.sqlite3'. Inside it, .htaccess
    // blocks data/ and *.sqlite3 from being served.
    'db_path' => __DIR__ . '/data/kssw.sqlite3',

    // Timezone for times in responses and for since/until without an offset.
    'timezone' => 'Asia/Tokyo',

    // Seconds between posts, per client_id and per client IP. 0 disables.
    'post_cooldown' => 5,

    // Browser origins allowed to call the API (CORS). [] = no CORS headers
    // (same-origin UI only); ['*'] = any origin.
    // Example: ['https://bbs.example.com', 'http://localhost:5173']
    'cors_origins' => [],

    // Take the client IP from X-Forwarded-For. Only behind a proxy you
    // control: otherwise clients pick their own IP and skip the per-IP limit.
    'trust_forwarded_for' => false,

    // Proxies in front of this host (IPs or CIDRs). From these only, the
    // client IP is read from X-Forwarded-For (from the right, so a client
    // cannot forge it); other clients' X-Forwarded-For is ignored. Use this
    // instead of trust_forwarded_for when you know the proxy's address.
    // XREA shared SSL (https://ss1.xrea.com/<account>.<server>.xrea.com/):
    //   'trusted_proxies' => ['203.189.105.202'],
    //   'trusted_proxy_https' => true,
    // (check REMOTE_ADDR and the Via header on your own server first).
    'trusted_proxies' => [],

    // Requests from a trusted proxy count as HTTPS (for the admin API's
    // https check). Set it only for a proxy that accepts HTTPS alone.
    // With trusted_proxies set, X-Forwarded-Proto / X-Forwarded-Ssl are read
    // from those proxies only.
    'trusted_proxy_https' => false,

    // SQLite journal mode ('DELETE', 'WAL', ...). '' keeps SQLite's default,
    // the safe choice on network file systems.
    'journal_mode' => '',

    // Maintenance mode is on while this file exists: every write answers
    // 503 {"detail":"maintenance","code":"maintenance"}, reads keep working. The admin API
    // (kssw-admin maintenance on/off) creates and removes it; so can you, by
    // FTP. Its directory must be writable for the admin API.
    'maintenance_file' => __DIR__ . '/data/maintenance',

    // Admin API (/v1/admin/..., used by the kssw-admin CLI and AI agents):
    // the SHA-256 (hex) of the admin token. '' disables it (every admin
    // endpoint answers 404). Make a token and this line with
    //   kssw-admin token new
    // on your own machine; keep the token there, put only the hash here.
    'admin_token_sha256' => '',

    // The admin API refuses plain http (403) except from this machine
    // itself. true allows it from anywhere: tests only.
    'admin_allow_http' => false,

    // admin.php (the minimal HTML admin page) login. While it is '',
    // admin.php shows a setup form (over HTTPS) that makes this whole line
    // from a password you type in the browser; replace the line below with
    // it. Or set the output of:
    //   php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
    // (or: php admin.php hash-password, which reads the password on stdin).
    'admin_password_hash' => '',

    // An admin session ends after this many idle minutes.
    'admin_session_minutes' => 60,

    // How the admin backup is made: 'auto' uses VACUUM INTO on SQLite
    // 3.27+ and a file copy inside a read transaction on older SQLite;
    // 'copy' always copies.
    'admin_backup_method' => 'auto',

    // Discovery (GET /.well-known/kssw, see ../well-known/README.md): the
    // API base it reports. '' = this directory's URL path (e.g. /bbs/api),
    // right when the document is served by this index.php through a
    // rewrite on the same host. Set an absolute URL otherwise.
    'discovery_base' => '',
    // An optional name for the board in the discovery document.
    'discovery_name' => '',
];
