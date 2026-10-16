<?php
// Queries over posts / tags / presence. Pure SQL, no HTTP.

declare(strict_types=1);

// One set of listing conditions, shared by list, count and search so a
// condition means the same thing everywhere (see the kssw specification).
final class PostFilter
{
    /** @var list<string> */
    public $tags = [];
    /** @var string */
    public $tagsOp = 'or';
    /** @var list<string> */
    public $excludeTags = [];
    /** @var string */
    public $excludeOp = 'or';
    /** @var string|null */
    public $author = null;
    /** @var bool */
    public $includeTree = false;
    /** @var string|null */
    public $rootId = null;
    /** @var list<string> */
    public $rootIds = [];
    /** @var bool */
    public $rootsOnly = false;
    /** @var list<string> */
    public $terms = [];
    /** @var string */
    public $qOp = 'and';
    /** @var string|null stored time */
    public $since = null;
    /** @var string|null stored time */
    public $until = null;
    /** @var bool */
    public $activeOnly = false;
}

final class Store
{
    public const POST_COLS = 'p.id, p.text, p.parent_id, p.root_id, p.author, p.client_id_hmac, p.status, p.created_at';
    // Presence rows: kept an hour (the longest active_count window), one
    // per IP per PRESENCE_DEDUPE_SEC.
    public const PRESENCE_KEEP_SEC = 3600;
    public const PRESENCE_DEDUPE_SEC = 30;

    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    private static function marks(int $n): string
    {
        return implode(', ', array_fill(0, $n, '?'));
    }

    public static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    // EXISTS on post_tags for posts row $alias.
    private static function hasTags(string $alias, array $tags, string $op, array &$params): string
    {
        if ($op === 'and') {
            $parts = [];
            foreach ($tags as $t) {
                $parts[] = "EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id = $alias.id AND pt.tag_name = ?)";
                $params[] = $t;
            }
            return '(' . implode(' AND ', $parts) . ')';
        }
        foreach ($tags as $t) {
            $params[] = $t;
        }
        return "EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id = $alias.id AND pt.tag_name IN (" . self::marks(count($tags)) . '))';
    }

    /** @return array{0: string, 1: list<mixed>} WHERE clause over `posts p` and its params */
    private static function where(PostFilter $f): array
    {
        $c = [];
        $params = [];
        if ($f->rootId !== null) {
            $c[] = 'p.root_id = ?';
            $params[] = $f->rootId;
        } elseif ($f->rootIds) {
            $c[] = 'p.root_id IN (' . self::marks(count($f->rootIds)) . ')';
            foreach ($f->rootIds as $r) {
                $params[] = $r;
            }
        } elseif ($f->includeTree && ($f->tags || $f->author !== null)) {
            $sub = [];
            if ($f->tags) {
                $sub[] = self::hasTags('m', $f->tags, $f->tagsOp, $params);
            }
            if ($f->author !== null) {
                $sub[] = 'm.author = ?';
                $params[] = $f->author;
            }
            if ($f->excludeTags) {
                $sub[] = 'NOT ' . self::hasTags('m', $f->excludeTags, $f->excludeOp, $params);
            }
            $c[] = 'p.root_id IN (SELECT m.root_id FROM posts m WHERE ' . implode(' AND ', $sub) . ')';
        } else {
            if ($f->rootsOnly) {
                $c[] = 'p.parent_id IS NULL';
            }
            if ($f->tags) {
                $c[] = self::hasTags('p', $f->tags, $f->tagsOp, $params);
            }
            if ($f->author !== null) {
                $c[] = 'p.author = ?';
                $params[] = $f->author;
            }
        }
        if ($f->excludeTags) {
            $c[] = 'NOT ' . self::hasTags('p', $f->excludeTags, $f->excludeOp, $params);
        }
        if ($f->terms) {
            // SQLite LIKE is case-insensitive for ASCII only.
            $likes = [];
            foreach ($f->terms as $t) {
                $likes[] = "p.text LIKE ? ESCAPE '\\'";
                $params[] = '%' . self::escapeLike($t) . '%';
            }
            $c[] = '(' . implode($f->qOp === 'or' ? ' OR ' : ' AND ', $likes) . ')';
        }
        if ($f->since !== null) {
            $c[] = 'p.created_at >= ?';
            $params[] = $f->since;
        }
        if ($f->until !== null) {
            $c[] = 'p.created_at < ?';
            $params[] = $f->until;
        }
        if ($f->activeOnly) {
            $c[] = "p.status = 'active'";
        }
        return [$c ? ' WHERE ' . implode(' AND ', $c) : '', $params];
    }

    public function countPosts(PostFilter $f): int
    {
        list($where, $params) = self::where($f);
        return (int) $this->db->value('SELECT COUNT(*) FROM posts p' . $where, $params);
    }

    // Newest first; a tree (rootId / rootIds) oldest first; rootsOnly by the
    // newest post of each tree, so a thread with a new reply comes back up.
    public function listPosts(PostFilter $f, int $page, int $pageSize): array
    {
        list($where, $params) = self::where($f);
        $sql = 'SELECT ' . self::POST_COLS . ' FROM posts p';
        if ($f->rootsOnly && $f->rootId === null && !$f->rootIds) {
            $sql .= ' LEFT JOIN (SELECT root_id, MAX(created_at) AS last_at FROM posts GROUP BY root_id) la ON la.root_id = p.id';
            $order = ' ORDER BY COALESCE(la.last_at, p.created_at) DESC, p.id DESC';
        } elseif ($f->rootId !== null || $f->rootIds) {
            $order = ' ORDER BY p.created_at ASC, p.id ASC';
        } else {
            $order = ' ORDER BY p.created_at DESC, p.id DESC';
        }
        $params[] = $pageSize;
        $params[] = ($page - 1) * $pageSize;
        return $this->db->all($sql . $where . $order . ' LIMIT ? OFFSET ?', $params);
    }

    public function getPost(string $id): ?array
    {
        return $this->db->one('SELECT ' . self::POST_COLS . ' FROM posts p WHERE p.id = ?', [$id]);
    }

    public function getPostsByIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return $this->db->all('SELECT ' . self::POST_COLS . ' FROM posts p WHERE p.id IN (' . self::marks(count($ids)) . ')', array_values($ids));
    }

    // [post_id => [tag, ...]], each list in code point order.
    public function tagsFor(array $postIds): array
    {
        $out = [];
        // Stay well under SQLite's default 999 host parameters.
        foreach (array_chunk(array_values($postIds), 500) as $chunk) {
            $rows = $this->db->all('SELECT post_id, tag_name FROM post_tags WHERE post_id IN (' . self::marks(count($chunk)) . ')', $chunk);
            foreach ($rows as $r) {
                $out[(string) $r['post_id']][] = (string) $r['tag_name'];
            }
        }
        foreach ($out as &$tags) {
            sort($tags, SORT_STRING);
        }
        unset($tags);
        return $out;
    }

    public function latestPostId(): ?string
    {
        $v = $this->db->value('SELECT id FROM posts ORDER BY created_at DESC, id DESC LIMIT 1');
        return $v === null ? null : (string) $v;
    }

    public function oldestPostAt(): ?string
    {
        $v = $this->db->value('SELECT MIN(created_at) FROM posts');
        return $v === null ? null : (string) $v;
    }

    // Newest created_at since $since from this IP or client, or null.
    public function lastPostAt(string $since, string $ipHmac, ?string $clientIdHmac): ?string
    {
        $sql = 'SELECT MAX(created_at) FROM posts WHERE created_at >= ? AND (ip_hmac = ?';
        $params = [$since, $ipHmac];
        if ($clientIdHmac !== null) {
            $sql .= ' OR client_id_hmac = ?';
            $params[] = $clientIdHmac;
        }
        $v = $this->db->value($sql . ')', $params);
        return $v === null ? null : (string) $v;
    }

    public function insertPost(string $text, ?string $parentId, ?string $rootId, ?string $author, ?string $clientIdHmac, string $ipHmac, array $tags, string $now): string
    {
        $id = uuid4();
        $this->db->run(
            "INSERT INTO posts (id, text, parent_id, root_id, author, client_id_hmac, ip_hmac, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)",
            [$id, $text, $parentId, $rootId ?? $id, $author, $clientIdHmac, $ipHmac, $now]
        );
        foreach ($tags as $t) {
            $this->db->run('INSERT OR IGNORE INTO tags (name, created_at) VALUES (?, ?)', [$t, $now]);
            $this->db->run('INSERT INTO post_tags (tag_name, post_id) VALUES (?, ?)', [$t, $id]);
        }
        return $id;
    }

    public function softDelete(string $id): void
    {
        $this->db->run("UPDATE posts SET status = 'deleted' WHERE id = ?", [$id]);
    }

    // ---- tags --------------------------------------------------------

    public function listTags(?string $q, array $names, string $sort, ?int $limit, int $offset, bool $withCounts): array
    {
        $c = [];
        $params = [];
        if ($q !== null && $q !== '') {
            $c[] = "t.name LIKE ? ESCAPE '\\'";
            $params[] = '%' . self::escapeLike($q) . '%';
        }
        if ($names) {
            $c[] = 't.name IN (' . self::marks(count($names)) . ')';
            foreach ($names as $n) {
                $params[] = $n;
            }
        }
        if ($sort === 'count' || $withCounts) {
            $sql = 'SELECT t.name, t.created_at, s.n, s.last_at FROM tags t LEFT JOIN ('
                . 'SELECT pt.tag_name AS tag_name, COUNT(*) AS n, MAX(sp.created_at) AS last_at'
                . ' FROM post_tags pt JOIN posts sp ON sp.id = pt.post_id GROUP BY pt.tag_name'
                . ') s ON s.tag_name = t.name';
        } else {
            $sql = 'SELECT t.name, t.created_at FROM tags t';
        }
        if ($c) {
            $sql .= ' WHERE ' . implode(' AND ', $c);
        }
        if ($sort === 'count') {
            $sql .= ' ORDER BY COALESCE(s.n, 0) DESC, t.name ASC';
        } elseif ($sort === 'name') {
            $sql .= ' ORDER BY t.name ASC';
        } else {
            $sql .= ' ORDER BY t.created_at DESC, t.name ASC';
        }
        if ($limit !== null || $offset > 0) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = $limit ?? -1;
            $params[] = $offset;
        }
        $out = [];
        foreach ($this->db->all($sql, $params) as $r) {
            $out[] = [
                'name' => (string) $r['name'],
                'created_at' => (string) $r['created_at'],
                'count' => $withCounts ? (int) ($r['n'] ?? 0) : null,
                'last_posted_at' => $withCounts && $r['last_at'] !== null ? (string) $r['last_at'] : null,
            ];
        }
        return $out;
    }

    // ---- presence ----------------------------------------------------

    // One row per IP per PRESENCE_DEDUPE_SEC; rows older than an hour go.
    public function recordPresence(string $ipHmac): void
    {
        $this->db->begin();
        try {
            $recent = $this->db->value(
                'SELECT 1 FROM presence WHERE ip_hmac = ? AND seen_at >= ? LIMIT 1',
                [$ipHmac, ts_ago(self::PRESENCE_DEDUPE_SEC)]
            );
            if ($recent === null) {
                $this->db->run('INSERT INTO presence (ip_hmac, seen_at) VALUES (?, ?)', [$ipHmac, ts_now()]);
            }
            $this->db->run('DELETE FROM presence WHERE seen_at < ?', [ts_ago(self::PRESENCE_KEEP_SEC)]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function activeCount(int $minutes): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(DISTINCT ip_hmac) FROM presence WHERE seen_at >= ?',
            [ts_ago($minutes * 60)]
        );
    }
}
