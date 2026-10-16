<?php
// Routing, validation, JSON out. The protocol is kssw 1: the specification
// and OpenAPI description live in ks-sw-spec (the commit in spec.lock).

declare(strict_types=1);

// The release version of this implementation (semver); the one place it
// is defined. KSSW_PROTOCOL_VERSION is the kssw major version it implements.
const KSSW_VERSION = '0.2.0-alpha.1';
const KSSW_PROTOCOL = 'kssw';
const KSSW_PROTOCOL_VERSION = '1';
const KSSW_PROFILE = 'min';
const KSSW_IMPL = 'php';

final class App
{
    public const TEXT_MAX_LEN = 5000;
    public const AUTHOR_MAX_LEN = 40;
    public const TAG_MAX_LEN = 64;
    public const TAGS_MAX = 20;
    public const CLIENT_ID_MAX_LEN = 128;
    public const ROOT_IDS_MAX = 50;
    public const BY_IDS_MAX = 100;
    public const TAG_NAMES_MAX = 200;
    public const PAGE_SIZE_MAX = 10000;
    public const SEARCH_PAGE_SIZE_MAX = 50;
    public const SEARCH_TERMS_MAX = 5;
    public const SEARCH_TERM_LEN = 100;

    // [path or regex, [method => handler], optional feature name];
    // literal paths before /v1/posts/{id}. GET /v1/meta lists the features
    // named here, so it cannot claim a route that does not exist; plus
    // `admin` (lib/admin_api.php) while an admin token is configured.
    private const ROUTES = [
        ['/v1/meta', ['GET' => 'meta']],
        ['/v1/posts', ['GET' => 'listPosts', 'POST' => 'createPost']],
        ['/v1/posts/count', ['GET' => 'countPosts']],
        ['/v1/posts/latest_id', ['GET' => 'latestId']],
        ['/v1/posts/oldest_at', ['GET' => 'oldestAt']],
        ['/v1/posts/search', ['GET' => 'search']],
        ['/v1/posts/search/count', ['GET' => 'searchCount']],
        ['/v1/posts/by-ids', ['GET' => 'byIds']],
        ['#^/v1/posts/([^/]+)$#', ['GET' => 'getPost', 'DELETE' => 'deletePost']],
        ['/v1/tags', ['GET' => 'listTags']],
        ['/v1/active_count', ['GET' => 'activeCount'], 'active_count'],
        ['/v1/presence', ['POST' => 'presence'], 'presence'],
        // Discovery (spec §6). It belongs at the host root: reachable here
        // when the API is at the root, or through a rewrite (php/well-known/).
        ['/.well-known/kssw', ['GET' => 'discovery']],
    ];

    // Error code by status, when a handler gives none (spec §5.6).
    private const CODES = [
        400 => 'bad_request', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed',
        413 => 'too_large', 422 => 'invalid', 429 => 'rate_limited', 500 => 'internal', 503 => 'unavailable',
    ];

    // Methods that never write.
    private const READ_METHODS = ['GET', 'HEAD'];

    /** @var array<string, mixed> */
    private $cfg;
    /** @var DateTimeZone */
    private $tz;
    /** @var Request */
    private $req;
    /** @var Db|null */
    private $dbConn = null;
    /** @var Store|null */
    private $storeObj = null;
    /** @var string|null|false false = not computed yet */
    private $viewer = false;

    public function __construct(array $cfg, Request $req)
    {
        $this->cfg = $cfg;
        $this->tz = new DateTimeZone((string) $cfg['timezone']);
        $this->req = $req;
    }

    public function run(): void
    {
        $req = $this->req;
        $headers = $this->cors();
        $cors = (bool) $headers;
        try {
            list($methods, $args) = self::route($req->path);
            if ($methods === null) {
                throw new HttpError(404, 'Not Found');
            }
            $allow = array_keys($methods);
            $allow[] = 'OPTIONS';
            sort($allow);
            $allow = implode(', ', $allow);
            if ($req->method === 'OPTIONS') {
                $headers['Allow'] = $allow;
                if ($cors) {
                    $headers['Access-Control-Allow-Methods'] = $allow;
                    $headers['Access-Control-Allow-Headers'] = 'Content-Type, X-Client-Id';
                    $headers['Access-Control-Max-Age'] = '600';
                }
                http_response_code(204);
                foreach ($headers as $k => $v) {
                    header("$k: $v");
                }
                return;
            }
            if (!isset($methods[$req->method])) {
                throw new HttpError(405, 'Method Not Allowed', ['Allow' => $allow]);
            }
            if (!in_array($req->method, self::READ_METHODS, true) && kssw_maintenance($this->cfg)) {
                throw new HttpError(503, 'maintenance', [], 'maintenance');
            }
            if ($req->path === '/.well-known/kssw') {
                // Public: any page may read it, whatever cors_origins says.
                $headers = ['Access-Control-Allow-Origin' => '*'];
            }
            $body = $this->{$methods[$req->method]}(...$args);
            send_json(200, $body, $headers);
        } catch (HttpError $e) {
            send_json($e->status, ['detail' => $e->detail, 'code' => $e->errorCode ?? (self::CODES[$e->status] ?? 'error')], $headers + $e->headers);
        } catch (Throwable $e) {
            error_log('ks-sw-api: ' . $e);
            send_json(500, ['detail' => 'internal error', 'code' => 'internal'], $headers);
        }
    }

    private static function route(string $path): array
    {
        foreach (self::ROUTES as $r) {
            $pattern = $r[0];
            $methods = $r[1];
            if ($pattern[0] === '#') {
                if (preg_match($pattern, $path, $m)) {
                    return [$methods, array_slice($m, 1)];
                }
            } elseif ($path === $pattern) {
                return [$methods, []];
            }
        }
        return [null, []];
    }

    private function cors(): array
    {
        $origins = $this->cfg['cors_origins'];
        $o = $this->req->origin;
        if (!$origins || $o === null || $o === '') {
            return [];
        }
        if (!in_array('*', $origins, true) && !in_array($o, $origins, true)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => $o,
            'Access-Control-Allow-Credentials' => 'true',
            'Vary' => 'Origin',
        ];
    }

    public static function features(array $cfg = []): array
    {
        $out = [];
        if ($cfg && kssw_admin_api($cfg) && !$cfg['admin_token_invalid']) {
            $out[] = 'admin';
        }
        foreach (self::ROUTES as $r) {
            if (isset($r[2]) && !in_array($r[2], $out, true)) {
                $out[] = $r[2];
            }
        }
        sort($out);
        return $out;
    }

    private function db(): Db
    {
        if ($this->dbConn === null) {
            // During maintenance the file may be away being edited: never
            // create a fresh, empty database in its place.
            if (!is_file((string) $this->cfg['db_path']) && kssw_maintenance($this->cfg)) {
                throw new HttpError(503, 'maintenance', [], 'maintenance');
            }
            $this->dbConn = new Db((string) $this->cfg['db_path'], (string) $this->cfg['journal_mode']);
        }
        return $this->dbConn;
    }

    private function store(): Store
    {
        if ($this->storeObj === null) {
            $this->storeObj = new Store($this->db());
        }
        return $this->storeObj;
    }

    // Keyed hash of X-Client-Id, or null when the caller sent none.
    private function viewerKey(): ?string
    {
        if ($this->viewer === false) {
            $cid = $this->req->clientId;
            $this->viewer = $cid === null ? null : $this->db()->key('cid', $cid);
        }
        return $this->viewer;
    }

    private function t(?string $stored): ?string
    {
        return ts_out($stored, $this->tz);
    }

    // ---- output ------------------------------------------------------

    /** @param string|null|false $viewerKey false = the caller's X-Client-Id */
    private function postsOut(array $rows, $viewerKey = false): array
    {
        if ($viewerKey === false) {
            $viewerKey = $this->viewerKey();
        }
        $tags = $this->store()->tagsFor(array_column($rows, 'id'));
        $out = [];
        foreach ($rows as $r) {
            $deleted = $r['status'] !== 'active';
            $author = $deleted || $r['author'] === null ? null : (string) $r['author'];
            $key = $r['client_id_hmac'] === null ? null : (string) $r['client_id_hmac'];
            $out[] = [
                'id' => (string) $r['id'],
                'text' => $deleted ? '' : utrim((string) $r['text']),
                'created_at' => $this->t((string) $r['created_at']),
                'tags' => $deleted ? [] : ($tags[(string) $r['id']] ?? []),
                'parent_id' => $r['parent_id'] === null ? null : (string) $r['parent_id'],
                'root_id' => (string) $r['root_id'],
                'status' => $deleted ? 'deleted' : 'active',
                'author' => $author,
                'mine' => $viewerKey === null ? null : ($key !== null && hash_equals($key, $viewerKey)),
            ];
        }
        return $out;
    }

    // ---- filters -----------------------------------------------------

    private function tagFilter(PostFilter $f): PostFilter
    {
        $r = $this->req;
        $f->tags = array_values(array_unique($r->list('tags')));
        $f->tagsOp = $r->op('tags_op', 'or');
        $f->excludeTags = array_values(array_unique($r->list('exclude_tags')));
        $f->excludeOp = $r->op('exclude_op', 'or');
        $a = utrim((string) $r->str('author'));
        $f->author = $a === '' ? null : $a;
        return $f;
    }

    private function searchFilter(): PostFilter
    {
        $r = $this->req;
        $f = $this->tagFilter(new PostFilter());
        $terms = [];
        foreach (usplit((string) $r->str('q')) as $t) {
            $t = uprefix($t, self::SEARCH_TERM_LEN);
            if (!in_array($t, $terms, true)) {
                $terms[] = $t;
            }
        }
        $f->terms = array_slice($terms, 0, self::SEARCH_TERMS_MAX);
        $f->qOp = $r->op('q_op', 'and');
        foreach (['since', 'until'] as $name) {
            $v = $r->str($name);
            if ($v === null || trim($v) === '') {
                continue;
            }
            $parsed = ts_parse_query($v, $this->tz);
            if ($parsed === null) {
                throw new HttpError(422, "$name must be an ISO 8601 date or datetime");
            }
            $f->$name = $parsed;
        }
        $f->activeOnly = true;
        if (!$f->terms && $f->since === null && $f->until === null && !$f->tags && !$f->excludeTags && $f->author === null) {
            throw new HttpError(400, 'q, tags, exclude_tags, author, since or until is required');
        }
        return $f;
    }

    // ---- handlers ----------------------------------------------------

    private function meta(): array
    {
        return [
            'protocol' => KSSW_PROTOCOL,
            'version' => KSSW_PROTOCOL_VERSION,
            'profile' => KSSW_PROFILE,
            'impl' => KSSW_IMPL,
            'impl_version' => KSSW_VERSION,
            'features' => self::features($this->cfg),
        ];
    }

    // The discovery document (spec §6): this API's base as a path-absolute
    // reference, from where the web server runs index.php, unless
    // discovery_base is set (e.g. an absolute URL).
    private function discovery(): array
    {
        $base = (string) $this->cfg['discovery_base'];
        $api = ['version' => KSSW_PROTOCOL_VERSION, 'base' => $base !== '' ? $base : $this->req->basePath()];
        if ((string) $this->cfg['discovery_name'] !== '') {
            $api['name'] = (string) $this->cfg['discovery_name'];
        }
        return ['protocol' => KSSW_PROTOCOL, 'apis' => [$api]];
    }

    private function listPosts(): array
    {
        $r = $this->req;
        $rootIds = array_values(array_unique($r->list('root_ids')));
        $rootId = trim((string) $r->str('root_id'));
        $rootsOnly = $r->bool('roots_only');
        if ($rootIds) {
            if (count($rootIds) > self::ROOT_IDS_MAX) {
                throw new HttpError(400, 'root_ids exceeds ' . self::ROOT_IDS_MAX);
            }
            if ($rootId !== '' || $rootsOnly) {
                throw new HttpError(400, 'root_ids cannot combine with root_id or roots_only');
            }
        }
        $f = $this->tagFilter(new PostFilter());
        $f->rootId = $rootId === '' ? null : $rootId;
        $f->rootIds = $rootIds;
        $f->rootsOnly = $rootsOnly;
        $f->includeTree = $r->bool('include_tree');
        $page = (int) $r->int('page', 1, 1);
        $size = (int) $r->int('page_size', 20, 1, self::PAGE_SIZE_MAX);
        return $this->postsOut($this->store()->listPosts($f, $page, $size));
    }

    private function countPosts(): int
    {
        $r = $this->req;
        $f = $this->tagFilter(new PostFilter());
        $rootId = trim((string) $r->str('root_id'));
        $f->rootId = $rootId === '' ? null : $rootId;
        $f->rootsOnly = $r->bool('roots_only');
        $f->includeTree = $r->bool('include_tree');
        return $this->store()->countPosts($f);
    }

    private function latestId(): array
    {
        return ['id' => $this->store()->latestPostId()];
    }

    private function oldestAt(): array
    {
        return ['created_at' => $this->t($this->store()->oldestPostAt())];
    }

    private function search(): array
    {
        $f = $this->searchFilter();
        $page = (int) $this->req->int('page', 1, 1);
        $size = (int) $this->req->int('page_size', 20, 1, self::SEARCH_PAGE_SIZE_MAX);
        return $this->postsOut($this->store()->listPosts($f, $page, $size));
    }

    private function searchCount(): int
    {
        return $this->store()->countPosts($this->searchFilter());
    }

    private function byIds(): array
    {
        if (!$this->req->has('ids')) {
            throw new HttpError(422, 'ids is required');
        }
        $raw = $this->req->list('ids');
        if (count($raw) > self::BY_IDS_MAX) {
            throw new HttpError(400, 'ids exceeds ' . self::BY_IDS_MAX);
        }
        $ids = [];
        foreach ($raw as $i) {
            $n = norm_uuid($i);
            if ($n !== null && !in_array($n, $ids, true)) {
                $ids[] = $n;
            }
        }
        return $this->postsOut($this->store()->getPostsByIds($ids));
    }

    private function find(string $rawId): array
    {
        $id = norm_uuid(rawurldecode($rawId));
        $row = $id === null ? null : $this->store()->getPost($id);
        if ($row === null) {
            throw new HttpError(404, 'Post not found', [], 'post_not_found');
        }
        return $row;
    }

    private function getPost(string $id): array
    {
        return $this->postsOut([$this->find($id)])[0];
    }

    private function deletePost(string $id): array
    {
        $row = $this->find($id);
        $vk = $this->viewerKey();
        if ($row['status'] !== 'active' || $vk === null || $row['client_id_hmac'] === null || !hash_equals((string) $row['client_id_hmac'], $vk)) {
            throw new HttpError(403, 'Forbidden');
        }
        $this->store()->softDelete((string) $row['id']);
        return ['ok' => true, 'status' => 'deleted'];
    }

    private function createPost(): array
    {
        $body = $this->req->json();

        $text = $body['text'] ?? null;
        if (!is_string($text)) {
            throw new HttpError(422, 'text is required and must be a string');
        }
        if (utrim($text) === '') {
            throw new HttpError(422, 'post body cannot be empty or whitespace only');
        }
        if (ulen($text) > self::TEXT_MAX_LEN) {
            throw new HttpError(422, 'post body exceeds ' . self::TEXT_MAX_LEN . ' characters');
        }

        $author = opt_str($body, 'author');
        if ($author !== null && ulen($author) > self::AUTHOR_MAX_LEN) {
            throw new HttpError(422, 'author exceeds ' . self::AUTHOR_MAX_LEN . ' characters');
        }

        $rawTags = $body['tags'] ?? [];
        if (!is_array($rawTags)) {
            throw new HttpError(422, 'tags must be a list of strings');
        }
        $tags = [];
        foreach ($rawTags as $t) {
            if (!is_string($t)) {
                throw new HttpError(422, 'tags must be a list of strings');
            }
            $t = utrim($t);
            if ($t === '' || in_array($t, $tags, true)) {
                continue;
            }
            if (ulen($t) > self::TAG_MAX_LEN) {
                throw new HttpError(422, 'a tag exceeds ' . self::TAG_MAX_LEN . ' characters');
            }
            $tags[] = $t;
        }
        if (count($tags) > self::TAGS_MAX) {
            throw new HttpError(422, 'more than ' . self::TAGS_MAX . ' tags');
        }

        $clientId = opt_str($body, 'client_id');
        if ($clientId !== null && ulen($clientId) > self::CLIENT_ID_MAX_LEN) {
            throw new HttpError(422, 'client_id exceeds ' . self::CLIENT_ID_MAX_LEN . ' characters');
        }

        $parentId = null;
        if (($body['parent_id'] ?? null) !== null) {
            $parentId = is_string($body['parent_id']) ? norm_uuid($body['parent_id']) : null;
            if ($parentId === null) {
                throw new HttpError(422, 'parent_id must be a UUID');
            }
        }

        $db = $this->db();
        $store = $this->store();
        $clientIdHmac = $clientId === null ? null : $db->key('cid', $clientId);
        $ipHmac = $db->key('ip', $this->req->ip);
        $cooldown = (int) $this->cfg['post_cooldown'];

        $db->begin();
        try {
            $rootId = null;
            if ($parentId !== null) {
                $parent = $store->getPost($parentId);
                if ($parent === null) {
                    throw new HttpError(422, 'parent post not found');
                }
                $rootId = (string) ($parent['root_id'] ?? $parent['id']);
            }
            if ($cooldown > 0) {
                $last = $store->lastPostAt(ts_ago($cooldown), $ipHmac, $clientIdHmac);
                if ($last !== null) {
                    $wait = max(1, (int) ($cooldown - ts_seconds_since($last)) + 1);
                    throw new HttpError(429, "Too many posts: wait $wait seconds before posting again", ['Retry-After' => (string) $wait]);
                }
            }
            $id = $store->insertPost($text, $parentId, $rootId, $author, $clientIdHmac, $ipHmac, $tags, ts_now());
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        // The writer is the caller: `mine` is judged by the client id it posted with.
        return $this->postsOut([$store->getPost($id)], $clientIdHmac)[0];
    }

    private function listTags(): array
    {
        $r = $this->req;
        $names = array_values(array_unique($r->list('names')));
        if (count($names) > self::TAG_NAMES_MAX) {
            throw new HttpError(400, 'names exceeds ' . self::TAG_NAMES_MAX);
        }
        $rows = $this->store()->listTags(
            $r->str('q'),
            $names,
            $r->choice('sort', 'recent', ['recent', 'count', 'name']),
            $r->int('limit', null, 1),
            (int) $r->int('offset', 0, 0),
            $r->bool('with_counts')
        );
        foreach ($rows as &$row) {
            $row['created_at'] = $this->t($row['created_at']);
            $row['last_posted_at'] = $this->t($row['last_posted_at']);
        }
        unset($row);
        return $rows;
    }

    private function activeCount(): array
    {
        return ['count' => $this->store()->activeCount((int) $this->req->int('minutes', 5, 1, 60))];
    }

    // Presence heartbeat (spec §8.2): an empty JSON object or no body;
    // members are ignored. Participants are told apart by a keyed hash of
    // the client IP.
    private function presence(): array
    {
        $this->req->json(true);
        $this->store()->recordPresence($this->db()->key('ip', $this->req->ip));
        return ['ok' => true];
    }
}
