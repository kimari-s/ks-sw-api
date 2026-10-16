# `/.well-known/kssw` (optional)

kssw clients can find a board's API from the host name alone through a
discovery document at `https://<host>/.well-known/kssw` (kssw spec §6):

```json
{"protocol": "kssw", "apis": [{"version": "1", "base": "/bbs/api"}]}
```

It lives at the **root of the host**, outside the API directory, so
`make deploy` and the API's `.htaccess` cannot put it there. It is
optional: UIs configured with the API URL work without it. Skip this if you
cannot write to the document root (some shared hosts).

Pick one of the two ways.

## A. Static file

1. Copy [`kssw`](kssw) (no extension) and [`.htaccess`](.htaccess) to
   `<document root>/.well-known/`. If that directory already has an
   `.htaccess` (often for certificates), append ours to it.
2. Edit `base` in `kssw` to the URL path of your API directory (where
   `index.php` is), e.g. `/bbs/api`, or an absolute URL. Add
   `"name": "…"` to the entry if you like.
3. Check: `curl -i https://<host>/.well-known/kssw` answers `200` with
   `Content-Type: application/json`.

## B. Rewrite to the API (Apache)

The API answers the discovery document itself at
`<API base>/.well-known/kssw`, with `base` set from where `index.php` runs
(override with `discovery_base` / `discovery_name` in `config.php`). Point
the host root at it with an internal rewrite in
`<document root>/.htaccess`:

```apache
RewriteEngine On
RewriteRule ^\.well-known/kssw$ /bbs/api/.well-known/kssw [L]
```

(`/bbs/api` is your API directory's URL path.) Without `mod_rewrite` at the
root, use A.

When the API itself is at the host root (the API base is `https://<host>`),
nothing is needed: `GET /.well-known/kssw` already reaches `index.php`.
