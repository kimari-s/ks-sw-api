<?php
// The admin API: /v1/admin/... (the kssw admin API, ks-sw-spec
// openapi/kssw-admin.openapi.yaml). Bearer token auth; JSON errors {"detail": ..., "code": ...}.
// Works during maintenance mode. No CORS: it is for CLIs and agents.

declare(strict_types=1);

final class AdminApi
{
    // The kssw admin API major version.
    public const VERSION = '1';
    public const PAGE_SIZE_DEFAULT = 50;
    public const LOG_PAGE_SIZE_DEFAULT = 50;
    // Failed token checks per client IP within AdminOps::AUTH_WINDOW_SEC
    // (shared with failed page logins).
    public const AUTH_MAX_FAILURES = 10;
    public const FEATURES = ['backup', 'maintenance'];

    // [path or regex, [method => handler]]; literal paths before {id}.
    private const ROUTES = [
        ['/v1/admin/capabilities', ['GET' => 'capabilities']],
        ['/v1/admin/posts', ['GET' => 'listPosts']],
        ['/v1/admin/posts/delete', ['POST' => 'deletePosts']],
        ['/v1/admin/posts/restore', ['POST' => 'restorePosts']],
        ['#^/v1/admin/posts/([^/]+)$#', ['GET' => 'getPost']],
        ['/v1/admin/maintenance', ['GET' => 'getMaintenance', 'PUT' => 'setMaintenance']],
        ['/v1/admin/backup', ['GET' => 'getBackup']],
        ['/v1/admin/log', ['GET' => 'listLog']],
    ];

    // Default code by status, when a handler gives none.
    private const CODES = [
        400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found',
        405 => 'method_not_allowed', 409 => 'conflict', 413 => 'too_large', 422 => 'invalid',
        429 => 'rate_limited', 500 => 'internal', 503 => 'unavailable',
    ];

    /** @var array<string, mixed> */
    private $cfg;
    /** @var Request */
    private $req;
    /** @var AdminOps */
    private $ops;
    /** @var array<string, mixed>|null the parsed JSON body */
    private $body = null;

    public function __construct(array $cfg, Request $req)
    {
        $this->cfg = $cfg;
        $this->req = $req;
        $this->ops = new AdminOps($cfg, 'api');
    }

    public static function handles(string $path): bool
    {
        return $path === '/v1/admin' || strncmp($path, '/v1/admin/', 10) === 0;
    }

    public function run(): void
    {
        $h = ['X-Content-Type-Options' => 'nosniff'];
        try {
            // No token configured: the feature is absent, like any unknown path.
            if (!kssw_admin_api($this->cfg)) {
                throw new HttpError(404, 'Not Found');
            }
            if ($this->cfg['admin_token_invalid']) {
                throw new HttpError(500, 'admin_token_sha256 in config.php must be 64 hex characters (kssw-admin token new)', [], 'config_invalid');
            }
            if (!$this->cfg['admin_allow_http'] && !$this->req->https() && !$this->req->fromLocalhost()) {
                throw new HttpError(403, 'the admin API needs HTTPS', [], 'https_required');
            }
            $this->authenticate();
            list($methods, $args) = self::route($this->req->path);
            if ($methods === null) {
                throw new HttpError(404, 'Not Found');
            }
            $method = $this->req->method === 'HEAD' ? 'GET' : $this->req->method;
            if (!isset($methods[$method])) {
                $allow = array_keys($methods);
                sort($allow);
                throw new HttpError(405, 'Method Not Allowed', ['Allow' => implode(', ', $allow)]);
            }
            $out = $this->{$methods[$method]}(...$args);
            if ($out !== null) {
                send_json(200, $out, $h);
            }
        } catch (HttpError $e) {
            send_json($e->status, ['detail' => $e->detail, 'code' => $e->errorCode ?? (self::CODES[$e->status] ?? 'error')], $h + $e->headers);
        } catch (Throwable $e) {
            error_log('ks-sw-api admin: ' . $e);
            send_json(500, ['detail' => 'internal error', 'code' => 'internal'], $h);
        }
    }

    private static function route(string $path): array
    {
        foreach (self::ROUTES as $r) {
            if ($r[0][0] === '#') {
                if (preg_match($r[0], $path, $m)) {
                    return [$r[1], array_slice($m, 1)];
                }
            } elseif ($path === $r[0]) {
                return [$r[1], []];
            }
        }
        return [null, []];
    }

    // Bearer token, compared by its SHA-256 in constant time. Failures are
    // counted per client IP; over the limit even the right token gets 429.
    private function authenticate(): void
    {
        $useDb = !$this->ops->dbMissing();
        $ipHmac = $useDb ? $this->ops->ipHmac($this->req->ip) : '';
        if ($useDb) {
            list($n, $wait) = $this->ops->failures($ipHmac);
            if ($n >= self::AUTH_MAX_FAILURES) {
                throw new HttpError(429, "too many failed authentications; retry in $wait seconds", ['Retry-After' => (string) $wait], 'rate_limited');
            }
        }
        $auth = trim($this->req->authorization());
        $token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : '';
        if ($token === '' || !hash_equals((string) $this->cfg['admin_token_sha256'], hash('sha256', $token))) {
            if ($useDb) {
                $this->ops->addFailure($ipHmac);
            }
            throw new HttpError(401, $token === '' ? 'missing bearer token' : 'invalid token', ['WWW-Authenticate' => 'Bearer realm="kssw-admin"'], 'unauthorized');
        }
    }

    // ---- input -------------------------------------------------------

    private function body(): array
    {
        if ($this->body === null) {
            $this->body = $this->req->json();
        }
        return $this->body;
    }

    // dry_run from the query string or the JSON body; either true wins.
    private function dryRun(bool $hasBody): bool
    {
        $q = $this->req->bool('dry_run');
        if (!$hasBody) {
            return $q;
        }
        $b = $this->body()['dry_run'] ?? false;
        if (!is_bool($b)) {
            throw new HttpError(422, 'dry_run must be a boolean');
        }
        return $q || $b;
    }

    private function time(string $name): ?string
    {
        $v = $this->req->str($name);
        if ($v === null || trim($v) === '') {
            return null;
        }
        $t = ts_parse_query($v, $this->ops->tz());
        if ($t === null) {
            throw new HttpError(422, "$name must be an ISO 8601 date or datetime");
        }
        return $t;
    }

    private function page(int $default): array
    {
        return [
            (int) $this->req->int('page', 1, 1),
            (int) $this->req->int('page_size', $default, 1, AdminOps::PAGE_SIZE_MAX),
        ];
    }

    private static function envelope(array $items, int $page, int $size, int $total): array
    {
        return ['items' => $items, 'page' => $page, 'page_size' => $size, 'total' => $total, 'has_more' => $page * $size < $total];
    }

    // ---- handlers ----------------------------------------------------

    private function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'impl' => KSSW_IMPL,
            'impl_version' => KSSW_VERSION,
            'features' => self::FEATURES,
            'operations' => ['getCapabilities', 'listPosts', 'getPost', 'deletePosts', 'restorePosts', 'getMaintenance', 'setMaintenance', 'getBackup', 'listLog'],
            'post_filters' => ['q', 'author', 'tags', 'status', 'root_id', 'since', 'until'],
            'limits' => ['page_size_max' => AdminOps::PAGE_SIZE_MAX, 'ids_max' => AdminOps::IDS_MAX],
            'timezone' => (string) $this->cfg['timezone'],
            'server_time' => ts_out(ts_now(), $this->ops->tz()),
        ];
    }

    private function listPosts(): array
    {
        $r = $this->req;
        $q = new AdminPostQuery();
        foreach (usplit((string) $r->str('q')) as $t) {
            $t = uprefix($t, AdminOps::TERM_LEN_MAX);
            if (!in_array($t, $q->terms, true)) {
                $q->terms[] = $t;
            }
        }
        if (count($q->terms) > AdminOps::TERMS_MAX) {
            throw new HttpError(422, 'q has more than ' . AdminOps::TERMS_MAX . ' terms');
        }
        $a = utrim((string) $r->str('author'));
        $q->author = $a === '' ? null : $a;
        $q->tags = array_values(array_unique(array_map('utrim', $r->list('tags'))));
        $q->status = $r->choice('status', 'all', ['active', 'deleted', 'all']);
        $root = $r->str('root_id');
        if ($root !== null && trim($root) !== '') {
            $q->rootId = norm_uuid($root);
            if ($q->rootId === null) {
                throw new HttpError(422, 'root_id must be a UUID');
            }
        }
        $q->since = $this->time('since');
        $q->until = $this->time('until');
        list($page, $size) = $this->page(self::PAGE_SIZE_DEFAULT);
        list($items, $total) = $this->ops->listPosts($q, $page, $size);
        return self::envelope($items, $page, $size, $total);
    }

    private function getPost(string $rawId): array
    {
        $id = norm_uuid(rawurldecode($rawId));
        $post = $id === null ? null : $this->ops->getPost($id);
        if ($post === null) {
            throw new HttpError(404, 'post not found', [], 'post_not_found');
        }
        return $post;
    }

    private function ids(): array
    {
        $ids = $this->body()['ids'] ?? null;
        if (!is_array($ids) || !$ids || array_keys($ids) !== range(0, count($ids) - 1)) {
            throw new HttpError(422, 'ids must be a non-empty list of post ids');
        }
        if (count($ids) > AdminOps::IDS_MAX) {
            throw new HttpError(422, 'ids has more than ' . AdminOps::IDS_MAX . ' entries');
        }
        $out = [];
        foreach ($ids as $i) {
            if (!is_string($i)) {
                throw new HttpError(422, 'ids must be strings');
            }
            if (!in_array($i, $out, true)) {
                $out[] = $i;
            }
        }
        return $out;
    }

    private function deletePosts(): array
    {
        $ids = $this->ids();
        return $this->ops->setStatus($ids, 'delete', $this->dryRun(true));
    }

    private function restorePosts(): array
    {
        $ids = $this->ids();
        return $this->ops->setStatus($ids, 'restore', $this->dryRun(true));
    }

    private function getMaintenance(): array
    {
        return $this->ops->maintenance();
    }

    private function setMaintenance(): array
    {
        $on = $this->body()['maintenance'] ?? null;
        if (!is_bool($on)) {
            throw new HttpError(422, 'maintenance (boolean) is required');
        }
        return $this->ops->setMaintenance($on, $this->dryRun(true));
    }

    private function getBackup(): ?array
    {
        $tmp = $this->ops->backupFile();
        try {
            $size = (int) filesize($tmp);
            $sha = (string) hash_file('sha256', $tmp);
            // Stream the file, not through index.php's output buffer.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(200);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $this->ops->backupName() . '"');
            header('Content-Length: ' . $size);
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('X-Backup-Format: sqlite3');
            header('X-Content-SHA256: ' . $sha);
            if ($this->req->method !== 'HEAD') {
                readfile($tmp);
            }
        } finally {
            @unlink($tmp);
        }
        return null;
    }

    private function listLog(): array
    {
        $a = $this->req->str('action');
        $a = $a === null || trim($a) === '' ? null : trim($a);
        list($page, $size) = $this->page(self::LOG_PAGE_SIZE_DEFAULT);
        list($items, $total) = $this->ops->logEntries($page, $size, $a);
        return self::envelope($items, $page, $size, $total);
    }
}
