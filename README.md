# ks-sw-api

The PHP reference implementation of **kssw**, the HTTP API between a
kuzuha-style BBS UI and its server. The protocol, its OpenAPI description
and its conformance suite live in
[ks-sw-spec](https://github.com/kimari-s/ks-sw-spec); this repository is
one implementation of it (`min` class, plus the admin API). It derives
from the API of [6umi.net](https://6umi.net), so UIs such as
[ks-kuzuha-ui](https://github.com/kimari-s/ks-kuzuha-ui) run against a
server you can drop onto ordinary shared hosting (PHP + SQLite, no daemon,
no database server).

```
php/                the implementation (PHP 7.4+, SQLite); see php/README.md
php/well-known/     template for /.well-known/kssw at the host root (optional)
schema.sql          its SQLite schema (implementation-internal, not part of kssw)
tools/kssw-admin    the admin CLI (Python 3.8+, stdlib only); see "Administration" below
spec.lock           the ks-sw-spec commit this implementation is tested against
tests/admin/        HTTP tests for things specific to php/ (admin page, errors); not the protocol
tests/cli/          tests of tools/kssw-admin against a live server
```

`GET /v1/meta` says what a server implements (kssw spec §7.1), e.g.

```json
{"protocol":"kssw","version":"1","profile":"min","impl":"php","impl_version":"0.2.0-alpha.1","features":["active_count","admin","presence"]}
```

- `protocol` / `version`: kssw, protocol version 1. `profile`: the
  conformance class (`min` here).
- `impl` / `impl_version`: this implementation and its release.
- `features`: optional parts of the protocol this server implements; a
  client calls them only when listed.

| feature | operation | class | php/ |
|---|---|---|---|
| `presence` | `POST /v1/presence` | min | yes |
| `active_count` | `GET /v1/active_count` | min | yes |
| `link_preview` | `GET /v1/links/by-urls` | full | no (404) |
| `tag_create` | `POST /v1/tags` | full | no (405) |
| `admin` | `/v1/admin/...` (kssw admin API) | min | yes, when an admin token is configured |

Errors are `{"detail": "...", "code": "..."}`. Any write may answer
`503 {"detail":"maintenance","code":"maintenance"}` while the server is in
maintenance mode; reads keep working.

What kssw leaves out on purpose: accounts and cookies, bots, automatic
tagging, translation, federation and web analytics. A poster is just a
name (`author`); tags are plain strings. Posts are at most 5,000
characters; by default one post per 5 s (`post_cooldown`) per browser
(`client_id`) and per IP, which is stored only as a keyed hash. The participant count (`active_count`)
comes from presence heartbeats kept for an hour at most.

Discovery: the API answers `GET <API base>/.well-known/kssw`; to put the
document at the host root, where clients look for it, see
[php/well-known/README.md](php/well-known/README.md).

Planned, not built yet: a no-JavaScript HTML view of the board and an RSS
feed, both served by the API itself. There is no spam filter in this
version.

## Administration (for AI agents)

The board is administered through the **admin API** (`/v1/admin/...`, the
kssw admin API: ks-sw-spec `openapi/kssw-admin.openapi.yaml`) with the CLI
[`tools/kssw-admin`](tools/kssw-admin). The CLI runs on the operator's (or
the agent's) machine, never on the server: one Python 3.8+ file, standard
library only, no install. Everything it prints is JSON, so an AI agent can
drive it directly.

`admin.php` (password login, recent posts, delete / undelete) is only a
minimal fallback for people without the CLI; see [php/README.md](php/README.md).

### Setup

1. Make a token, on your own machine (nothing is sent anywhere):

	```
	$ tools/kssw-admin token new
	{
	  "token": "3iSmaIsekLlx73TkCofCNMVl55RywNuFKZp1Hmj_Ppo",
	  "sha256": "c3ddb18a7580a45c201233d337cd46750c7278bf5fce759e9f0fb26a3a0a5ccd",
	  "config_line": "'admin_token_sha256' => 'c3ddb18a7580a45c201233d337cd46750c7278bf5fce759e9f0fb26a3a0a5ccd',",
	  "note": "keep the token on your machine (KSSW_ADMIN_TOKEN); put only config_line into the server's config.php"
	}
	```

	The token is 32 random bytes (base64url). Keep it like a password.
2. Put `config_line` into the server's `api/config.php` (copy
	`config.example.php` first if there is none) and upload it. The server
	stores only the SHA-256; it never sees or keeps the token itself. To
	revoke, replace or delete the line.
3. Serve the board over **HTTPS**. The admin API answers `403
	https_required` to plain http (except from the server's own machine),
	and the CLI refuses to send the token to a non-localhost `http://` URL.
4. Check: `GET /v1/meta` now lists `admin`, and `kssw-admin capabilities`
	answers.

Without `admin_token_sha256` every `/v1/admin/...` path answers 404 and
`/v1/meta` does not list `admin`. Ten failed authentications from one IP
within 15 minutes block that IP for the rest of the window (`429`); failed
`admin.php` logins count too.

### Environment

| variable | meaning |
|---|---|
| `KSSW_URL` | the board API base, e.g. `https://example.com/bbs/api` (where `/v1/meta` lives); or `--url` |
| `KSSW_ADMIN_TOKEN` | the token; or `--token-file FILE` / `KSSW_ADMIN_TOKEN_FILE`; or `--token` (visible in the process list) |

Global options (before or after the command): `--url`, `--token`,
`--token-file`, `--format json|table` (default `json`), `--timeout SECONDS`
(default 60), `--allow-http` (tests only). The token is never printed
(only `token new` prints the token it makes), redirects are never followed
(they would carry the token elsewhere), and nothing ever prompts.

### Output and exit codes

stdout: one JSON document per invocation (`--format table` for people).
On error, stdout is empty and stderr holds one JSON document:

```
{"error": {"code": "unauthorized", "message": "invalid token", "status": 401}}
```

`code` is the server's error code (`unauthorized`, `https_required`,
`rate_limited`, `post_not_found`, `invalid`, `database_missing`, ...) or
the CLI's own (`usage`, `missing_url`, `missing_token`, `http_refused`,
`network`, `wrong_url`, `admin_disabled`, `file_exists`, ...); `status` is
the HTTP status or null.

| exit | meaning |
|---|---|
| 0 | ok |
| 1 | usage error, or the server rejected the request as malformed (400, 422) |
| 2 | authentication: missing / wrong token, HTTPS required, rate limited (401, 403, 429) |
| 3 | not found: a post id (also: any unknown id in `posts delete` / `restore`), or no admin API at that URL (404) |
| 4 | conflict or maintenance state (409, 503); `backup` target file exists |
| 5 | server error, network error, unexpected response (5xx, other) |

### Commands

`kssw-admin commands` prints all of this as JSON (every command, its
arguments and output shape, the exit codes); `--help` works on every
command.

**`capabilities`**: what this server's admin API supports.

```
$ kssw-admin capabilities
{"version": "1", "impl": "php", "impl_version": "0.2.0-alpha.1", "features": ["backup", "maintenance"],
 "operations": ["getCapabilities", "listPosts", "getPost", "deletePosts", "restorePosts",
                "getMaintenance", "setMaintenance", "getBackup", "listLog"],
 "post_filters": ["q", "author", "tags", "status", "root_id", "since", "until"],
 "limits": {"page_size_max": 200, "ids_max": 100},
 "timezone": "Asia/Tokyo", "server_time": "2026-10-16T11:19:11.800719+09:00"}
```

**`posts list`**: posts newest first, deleted ones included, with their
full text. Filters narrow (AND): `--q` (text contains every term; ASCII
case-insensitive), `--author` (exact), `--tag` (repeatable: any of them),
`--status active|deleted|all`, `--root ROOT_ID` (one thread), `--since` /
`--until` (ISO 8601; without an offset, the server's timezone).
`--page`, `--page-size` (≤ 200); `--all` follows the pages (`--limit N`
stops after N).

```
$ kssw-admin posts list --author spammer --page-size 1
{"items": [{"id": "cbe4e61e-7c7f-43c4-b65a-f8423a6954d8", "created_at": "2026-10-16T11:19:11.644596+09:00",
            "status": "active", "author": "spammer", "tags": [], "text": "cheap pills again",
            "parent_id": null, "root_id": "cbe4e61e-7c7f-43c4-b65a-f8423a6954d8"}],
 "page": 1, "page_size": 1, "total": 2, "has_more": true}
```

**`posts get ID`**: one post (exit 3, `post_not_found`, if there is none).

**`posts delete ID...`** / **`posts restore ID...`**: soft-delete (the
same as a poster's own delete: the board shows the post as deleted) or
undo it. Idempotent. `--dry-run` reports and changes nothing. Ids as
arguments, or `--stdin` (or `-`): whitespace-separated ids, a JSON list of
ids or posts, or a `posts list` result. Any number of ids (sent 100 per
request). Exit 3 when any id is unknown or malformed; the others are
still processed.

```
$ kssw-admin posts list --author spammer | kssw-admin posts delete --stdin --dry-run
{"dry_run": true, "changed": 2, "results": [
  {"id": "cbe4e61e-7c7f-43c4-b65a-f8423a6954d8", "result": "changed", "status_before": "active", "status_after": "deleted"},
  {"id": "ed86b057-aa35-4498-862c-4f62dc90cc28", "result": "changed", "status_before": "active", "status_after": "deleted"}]}
```

`result` is `changed`, `unchanged` (already in that state), `not_found` or
`invalid_id`.

**`maintenance get|on|off`**: while on, the board refuses new posts and
deletes (`503`); reads and the admin API keep working. `on` / `off` take
`--dry-run`. `off` is refused (`409 database_missing`, exit 4) while the
database file is missing.

```
$ kssw-admin maintenance on
{"dry_run": false, "changed": true, "maintenance": true, "since": "2026-10-16T11:19:12.000000+09:00", "database": "present"}
```

**`backup FILE`**: a consistent snapshot of the database (a SQLite file),
written atomically to FILE and checked against the server's SHA-256.
Works during maintenance. `--force` overwrites FILE.

```
$ kssw-admin backup kssw.sqlite3
{"file": "/home/me/kssw.sqlite3", "bytes": 90112, "sha256": "1da84c4009edfc754f481ab8f8fa6b81f7a699908ea3f698836bfaa43302dd0a",
 "sha256_verified": true, "format": "sqlite3", "server_filename": "kssw-backup-20261016-111912.sqlite3"}
```

**`log`**: the admin log, newest first: every delete / restore (one entry
per post, dry runs included), maintenance change and backup, from the API
(`via: api`) and from `admin.php` (`via: html`, also `login` / `logout`).
`--action NAME`, `--page`, `--page-size`.

```
$ kssw-admin log --page-size 1
{"items": [{"id": 7, "created_at": "2026-10-16T11:19:12.676937+09:00", "action": "backup", "target": null,
            "dry_run": false, "result": "ok", "via": "api"}],
 "page": 1, "page_size": 1, "total": 7, "has_more": true}
```

**`token new`** / **`token hash`**: make a token and its `config.php`
line; or the line for an existing token (`KSSW_ADMIN_TOKEN`, or `--stdin`).
Offline.

### For the operator: a prompt for your agent

Paste this (with your URL) into your AI agent's instructions:

```
You administer a kssw bulletin board with the CLI `kssw-admin`
(path: tools/kssw-admin). KSSW_URL and KSSW_ADMIN_TOKEN are set in the
environment; never print, log or pass the token on the command line.
- Run `kssw-admin commands` once to learn every command, argument and output.
- Every command prints one JSON document on stdout; on failure stdout is
  empty and stderr has {"error": {"code", "message", "status"}}.
  Exit codes: 0 ok, 1 usage, 2 auth, 3 not found, 4 conflict/maintenance,
  5 server/network.
- Find posts with `kssw-admin posts list` (--q, --author, --tag, --status,
  --since, --until, --root; --all to page through).
- Before any change, run it with --dry-run and check the "results"; then
  run it again without --dry-run. Deletes are soft and reversible with
  `posts restore`. Pipe a `posts list` result into `posts delete --stdin`.
- Take `kssw-admin backup <file>` before bulk changes.
- Do not turn maintenance mode on or off unless asked.
- `kssw-admin log` shows what was done, by you or on the admin page.
```

## Tests

```
make test                                  # PHP 7.4 / 8.3 / Apache in docker: kssw conformance, tests/admin, tests/cli
make conformance BASE_URL=http://host/api  # the kssw conformance suite against any server (it writes posts: never production)
make conformance BASE_URL=... PYTEST_ARGS=...   # with ADMIN_TOKEN=... in the environment: the admin API too
```

Needs docker and [uv](https://docs.astral.sh/uv/). The conformance suite
comes from ks-sw-spec at the commit in [`spec.lock`](spec.lock): `make
spec` (run by the targets above) checks it out into `.scratch/spec`. The
URL in `spec.lock` is the public repository; until it is public, set
`SPEC_URL` (a local clone or a private remote) in `make.local`, or
`SPEC_DIR=../ks-sw-spec` to use a checkout as it is. When the code needs a
newer revision of the protocol, bump `commit=` in `spec.lock` in the same
change.

Tests of optional operations run only when the server lists their feature
in `/v1/meta` (admin features: in `/v1/admin/capabilities`). The admin API
tests need `ADMIN_TOKEN` and a server that accepts it over plain http
(`admin_allow_http`, a test setting). The server under test must trust
`X-Forwarded-For` (see the suite's `conformance/conftest.py`).

CI (`.github/workflows/ci.yml`) runs the same targets.

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability.

## License

MIT — see [LICENSE](LICENSE).
