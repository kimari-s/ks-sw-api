# Security policy

ks-sw-api runs on public web servers and holds an admin API protected by a
bearer token, so security reports are welcome. Examples of what to report:

- a way to use the admin API (`/v1/admin/...`) or `admin.php` without the
  token or password, or to get around the HTTPS or rate-limit checks;
- deleting or restoring other people's posts without the admin token;
- reading raw client ids, IP addresses, the HMAC secret, `config.php` or
  the SQLite database over HTTP (for example through a missing
  `.htaccess` rule);
- anything that makes the server run code or SQL it should not, or turns
  a PHP error into a leak.

A flaw in the protocol itself (every conforming server affected) belongs
to [ks-sw-spec](https://github.com/kimari-s/ks-sw-spec).

## How to report

1. **Preferred:** GitHub private vulnerability reporting — the
   repository's **Security** tab → **Report a vulnerability**.
2. **Fallback:** email **security@6umi.net** (the RFC 2142 `security@`
   mailbox).

Please do not open a public issue for an undisclosed vulnerability. We aim
to acknowledge a report within a week. There is no bug bounty.

## Supported versions

The latest release. This is alpha software: fixes land in the next
release, not in old ones.
