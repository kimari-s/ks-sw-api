# php/ — the kssw reference implementation (PHP, `min`)

`api/` is the deployable directory: a front controller (`index.php`, the
API and the admin API), the minimal admin page (`admin.php`), their
`lib/`, `.htaccess` and `config.example.php`.

Version 0.2.0-alpha.1 (`KSSW_VERSION` in `lib/app.php`, the one place it is set).
Implements kssw protocol 1 (ks-sw-spec at the commit in `../spec.lock`).
Features: `presence`, `active_count`, and `admin` while an admin token is
configured. `GET /v1/links/by-urls` and `POST /v1/tags` are not
implemented.

- PHP 7.4 – 8.x. Extensions: PDO + pdo_sqlite, json, hash, PCRE (all
  built in on common hosts). No mbstring, no Composer.
- SQLite only. Tables are created on the first request (`lib/schema.sql`,
  a copy of `../schema.sql`; `make check-schema` keeps them equal; the
  admin tables: `lib/admin.sql`).
- Apache: `.htaccess` rewrites `<dir>/v1/*` to `index.php?__route=/v1/*`
  (works under mod_php, CGI and FastCGI), passes the `Authorization`
  header on to PHP (CGI / FastCGI drop it otherwise) and denies `lib/`,
  `data/`, `config.php` and database files. Without the rewrite,
  `index.php/v1/...` (PATH_INFO) also works. `admin.php` is served as a
  plain PHP file.
- PHP warnings never reach the client and a fatal error becomes
  `500 {"detail":"internal error","code":"internal"}` (`index.php` turns
  `display_errors` off and buffers the output), whatever the host's
  `display_errors` says. Errors go to the server's error log.

## Deploy

1. Upload `api/` to e.g. `public_html/api/` (`make deploy` with
   `DEPLOY_HOST` / `DEPLOY_PATH` in `make.local`; it never touches
   `config.php` or `data/` on the server).
2. Optionally copy `config.example.php` to `config.php`: database location
   (best outside the document root), timezone, CORS origins for a UI on
   another origin, cooldown, and `admin_token_sha256` for the admin API.
3. `curl https://example.com/api/v1/meta` →
   `{"protocol":"kssw","version":"1","profile":"min","impl":"php","impl_version":"0.2.0-alpha.1","impl_url":"https://github.com/kimari-s/ks-sw-api","features":["active_count","presence"]}`
   (plus `"admin"` once a token is configured).
4. Optionally publish `/.well-known/kssw` at the host root
   ([`well-known/README.md`](well-known/README.md)).

The directory holding the database must be writable by the PHP user.

## Admin API

`/v1/admin/...`, the kssw admin API (ks-sw-spec
`openapi/kssw-admin.openapi.yaml`); driven by
`../tools/kssw-admin` (setup, commands, exit codes: "Administration" in
`../README.md`). Enabled by `admin_token_sha256` in `config.php` (the
SHA-256 of a token from `kssw-admin token new`); without it every admin
path answers 404. Code: `lib/admin_api.php` (HTTP) and `lib/admin.php`
(the logic, shared with the page).

- `Authorization: Bearer <token>`, compared by SHA-256 with `hash_equals`.
- Plain http is refused (`403 https_required`) unless the request comes
  from the server's own machine without a proxy header, or
  `admin_allow_http` is true (tests). HTTPS is recognised from `HTTPS`,
  `REQUEST_SCHEME`, `X-Forwarded-Proto: https` or `X-Forwarded-Ssl: on`.
  With `trusted_proxies` set, the two headers count only from those
  proxies (and `trusted_proxy_https` makes every request from them HTTPS).
- At most 10 failed authentications per client IP per 15 minutes (keyed
  hash in `admin_login_failures`, shared with page logins), then `429`.
- Keeps working in maintenance mode, edits included. No CORS headers.
- Backup: `VACUUM INTO` on SQLite 3.27+, else a file copy inside a read
  transaction (`admin_backup_method`), streamed as
  `application/octet-stream` with `X-Content-SHA256`.
- Admin log (`admin_log`): time, action, target, dry run, result, via
  (`api` / `html`).

## Admin page (minimal fallback)

`https://example.com/api/admin.php`: for someone who just wants to remove
a post from a browser, without Python. Plain HTML forms, no JavaScript
needed: log in with a password, recent posts 50 per page (newest first,
deleted ones greyed with their text), delete / undelete, log out. Search,
maintenance, backup and the admin log are in the admin API only.

Setup, in the browser: while `admin_password_hash` is empty, `admin.php`
is a setup form. Type the password twice; the page answers the line for
`config.php` (`'admin_password_hash' => '$2y$10$...',`) and the steps to
put it there by FTP (copy `config.example.php` to `config.php` if
missing, replace the `'admin_password_hash' => '',` line, upload,
reload). Once the hash is in `config.php`, the form is gone and the page
is the login.

- It writes no file and stores nothing: the hash is computed
  (`password_hash`, `PASSWORD_DEFAULT`) and shown once. The only write is
  the rate limiter's counter in the database.
- HTTPS only, like the admin API: over plain http it answers 403
  (except from the server's own machine, or with `admin_allow_http`).
- Password: at least 12 characters, at most 72 bytes (bcrypt's limit).
- A CSRF token on the form; at most 10 posts per client IP per 15 minutes
  (`admin_login_failures`, a counter separate from logins), then `429`.

Or make the hash yourself with PHP:

```
php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
# or, without the password on the command line:
php admin.php hash-password
```

Put the output in `config.php` as `'admin_password_hash' => '$2y$10$...'`
(single quotes: the hash contains `$`). The hash is portable: any machine
with PHP can make it.

Security: a PHP session cookie (`kssw_admin`; HttpOnly, SameSite=Strict,
Secure under HTTPS; ends after `admin_session_minutes` idle minutes), a
CSRF token on every form, and at most 5 failed logins per 15 minutes per
IP. Serve it over HTTPS only.

## Maintenance mode

While the file `data/maintenance` exists (`maintenance_file` in
`config.php`), every write (`POST`, `DELETE`) answers
`503 {"detail":"maintenance","code":"maintenance"}`; reads and the admin API keep working.
`kssw-admin maintenance on|off` creates and removes the file; an empty file
uploaded by FTP works the same.

Editing the database by hand, safely:

1. Turn maintenance mode on (`kssw-admin maintenance on`, or upload an
   empty `data/maintenance`).
2. Download the database: `kssw-admin backup kssw.sqlite3`, or the file by
   FTP (`data/kssw.sqlite3` or your `db_path`).
3. Edit the copy, e.g. with [DB Browser for SQLite](https://sqlitebrowser.org/).
4. Upload it over the old file.
5. Turn maintenance mode off (`kssw-admin maintenance off`, or delete
   `data/maintenance`).

Admin edits (API or page) still work in maintenance mode: an edit made
between steps 2 and 4 is lost when the copy is uploaded.

With SSH, skip the copies: turn maintenance mode on, then
`sqlite3 data/kssw.sqlite3` and edit in place, then turn it off.

With `journal_mode` WAL, download by FTP only if no `-wal` file sits next
to the database (the admin backup is always complete). While maintenance
mode is on, a missing database file is not recreated: requests that need
it answer 503 (`database_missing` on the admin API) until the upload is
back, and `maintenance off` is refused.

## Test

`make test` (docker): lint on 7.4 and 8.3; the kssw conformance suite
(from ks-sw-spec, with the admin API), the PHP tests (`tests/admin/`:
admin page, rate limits, error handling) and the CLI tests (`tests/cli/`)
against `php -S` on `php:7.4-cli` and `php:8.3-cli` (the latter with the
file-copy backup), and against Apache + `.htaccess` on `php:7.4-apache`
with checks that private files are not served and `admin.php` is, and a
document-root rewrite of `/.well-known/kssw` (`well-known/README.md`); the CLI
on `python:3.8-slim`; a run without password hash and token (admin
disabled); a run with `admin_allow_http` off (`403 https_required`). Test
settings: `tests/config.test.php`.
