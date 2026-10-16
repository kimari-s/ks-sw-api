<?php
// Administration logic shared by the admin API (lib/admin_api.php, the
// kssw admin API, ks-sw-spec) and the minimal HTML page
// (admin.php, lib/admin_page.php). No HTTP here.

declare(strict_types=1);

// Filters of an admin post listing. Every condition narrows (AND).
final class AdminPostQuery
{
    /** @var list<string> text terms (substring, ASCII case-insensitive), all must match */
    public $terms = [];
    /** @var string|null exact author */
    public $author = null;
    /** @var list<string> tags, any of them */
    public $tags = [];
    /** @var string active | deleted | all */
    public $status = 'all';
    /** @var string|null */
    public $rootId = null;
    /** @var string|null stored time, inclusive */
    public $since = null;
    /** @var string|null stored time, exclusive */
    public $until = null;
}

final class AdminOps
{
    public const PAGE_SIZE_MAX = 200;
    public const IDS_MAX = 100;
    public const TERMS_MAX = 5;
    public const TERM_LEN_MAX = 100;
    // Failed admin authentications (API tokens and page logins) per client
    // IP, kept as a keyed hash in admin_login_failures.
    public const AUTH_WINDOW_SEC = 900;
    // Bumped when admin.sql changes.
    private const ADMIN_SCHEMA = '1';

    /** @var array<string, mixed> */
    private $cfg;
    /** @var DateTimeZone */
    private $tz;
    /** @var string api | html: recorded with each log entry */
    private $via;
    /** @var Db|null */
    private $dbConn = null;

    public function __construct(array $cfg, string $via)
    {
        $this->cfg = $cfg;
        $this->tz = new DateTimeZone((string) $cfg['timezone']);
        $this->via = $via;
    }

    public function tz(): DateTimeZone
    {
        return $this->tz;
    }

    // During maintenance the database file may be away being edited; it is
    // then never recreated empty.
    public function dbMissing(): bool
    {
        return !is_file((string) $this->cfg['db_path']) && kssw_maintenance($this->cfg);
    }

    public function db(): Db
    {
        if ($this->dbConn === null) {
            if ($this->dbMissing()) {
                throw new HttpError(503, 'maintenance mode is on and the database file is missing; upload it first', [], 'database_missing');
            }
            $db = new Db((string) $this->cfg['db_path'], (string) $this->cfg['journal_mode']);
            if ($db->value("SELECT value FROM meta WHERE name = 'admin_schema'") !== self::ADMIN_SCHEMA) {
                self::install($db);
            }
            $this->dbConn = $db;
        }
        return $this->dbConn;
    }

    private static function install(Db $db): void
    {
        $sql = file_get_contents(__DIR__ . '/admin.sql');
        if ($sql === false) {
            throw new RuntimeException('admin.sql missing');
        }
        $db->pdo->exec($sql);
        $db->run("INSERT OR REPLACE INTO meta (name, value) VALUES ('admin_schema', ?)", [self::ADMIN_SCHEMA]);
    }

    private function t(?string $stored): ?string
    {
        return ts_out($stored, $this->tz);
    }

    // ---- admin log ---------------------------------------------------

    public function log(string $action, ?string $target, bool $dryRun, string $result): void
    {
        if ($this->dbMissing()) {
            return; // nowhere to write; the action itself said so
        }
        $this->db()->run(
            'INSERT INTO admin_log (created_at, action, target, dry_run, result, via) VALUES (?, ?, ?, ?, ?, ?)',
            [ts_now(), $action, $target === null ? null : uprefix($target, 100), $dryRun ? 1 : 0, $result, $this->via]
        );
    }

    /** @return array{0: list<array>, 1: int} entries newest first, total */
    public function logEntries(int $page, int $size, ?string $action): array
    {
        $db = $this->db();
        $where = $action === null ? '' : ' WHERE action = ?';
        $params = $action === null ? [] : [$action];
        $total = (int) $db->value('SELECT COUNT(*) FROM admin_log' . $where, $params);
        $params[] = $size;
        $params[] = ($page - 1) * $size;
        $out = [];
        foreach ($db->all('SELECT id, created_at, action, target, dry_run, result, via FROM admin_log' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?', $params) as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'created_at' => $this->t((string) $r['created_at']),
                'action' => (string) $r['action'],
                'target' => $r['target'] === null ? null : (string) $r['target'],
                'dry_run' => (bool) $r['dry_run'],
                'result' => $r['result'] === null ? null : (string) $r['result'],
                'via' => $r['via'] === null ? null : (string) $r['via'],
            ];
        }
        return [$out, $total];
    }

    // ---- failed authentications -------------------------------------

    public function ipHmac(string $ip): string
    {
        return $this->db()->key('ip', $ip);
    }

    /** @return array{0: int, 1: int} failures in the window, seconds until the oldest expires */
    public function failures(string $ipHmac): array
    {
        $db = $this->db();
        $since = ts_ago(self::AUTH_WINDOW_SEC);
        $db->run('DELETE FROM admin_login_failures WHERE created_at < ?', [$since]);
        $row = $db->one('SELECT COUNT(*) AS n, MIN(created_at) AS first FROM admin_login_failures WHERE ip_hmac = ? AND created_at >= ?', [$ipHmac, $since]);
        $n = (int) ($row['n'] ?? 0);
        $wait = $n === 0 ? 0 : max(1, (int) ceil(self::AUTH_WINDOW_SEC - ts_seconds_since((string) $row['first'])));
        return [$n, $wait];
    }

    public function addFailure(string $ipHmac): void
    {
        $this->db()->run('INSERT INTO admin_login_failures (ip_hmac, created_at) VALUES (?, ?)', [$ipHmac, ts_now()]);
    }

    public function clearFailures(string $ipHmac): void
    {
        $this->db()->run('DELETE FROM admin_login_failures WHERE ip_hmac = ?', [$ipHmac]);
    }

    // ---- posts -------------------------------------------------------

    /** @return array{0: string, 1: list<mixed>} */
    private static function where(AdminPostQuery $q): array
    {
        $c = [];
        $params = [];
        foreach ($q->terms as $t) {
            $c[] = "p.text LIKE ? ESCAPE '\\'";
            $params[] = '%' . Store::escapeLike($t) . '%';
        }
        if ($q->author !== null) {
            $c[] = 'p.author = ?';
            $params[] = $q->author;
        }
        if ($q->tags) {
            $c[] = 'EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id = p.id AND pt.tag_name IN (' . implode(', ', array_fill(0, count($q->tags), '?')) . '))';
            foreach ($q->tags as $t) {
                $params[] = $t;
            }
        }
        if ($q->status !== 'all') {
            $c[] = 'p.status = ?';
            $params[] = $q->status;
        }
        if ($q->rootId !== null) {
            $c[] = 'p.root_id = ?';
            $params[] = $q->rootId;
        }
        if ($q->since !== null) {
            $c[] = 'p.created_at >= ?';
            $params[] = $q->since;
        }
        if ($q->until !== null) {
            $c[] = 'p.created_at < ?';
            $params[] = $q->until;
        }
        return [$c ? ' WHERE ' . implode(' AND ', $c) : '', $params];
    }

    /** @return array{0: list<array>, 1: int} posts newest first (output form), total */
    public function listPosts(AdminPostQuery $q, int $page, int $size): array
    {
        list($where, $params) = self::where($q);
        $db = $this->db();
        $total = (int) $db->value('SELECT COUNT(*) FROM posts p' . $where, $params);
        $params[] = $size;
        $params[] = ($page - 1) * $size;
        $rows = $db->all('SELECT ' . Store::POST_COLS . ' FROM posts p' . $where . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?', $params);
        return [$this->postsOut($rows), $total];
    }

    public function getPost(string $id): ?array
    {
        $row = (new Store($this->db()))->getPost($id);
        return $row === null ? null : $this->postsOut([$row])[0];
    }

    // What an admin sees: deleted posts keep their text, tags and author.
    private function postsOut(array $rows): array
    {
        $tags = (new Store($this->db()))->tagsFor(array_column($rows, 'id'));
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (string) $r['id'],
                'created_at' => $this->t((string) $r['created_at']),
                'status' => $r['status'] === 'active' ? 'active' : 'deleted',
                'author' => $r['author'] === null ? null : (string) $r['author'],
                'tags' => $tags[(string) $r['id']] ?? [],
                'text' => (string) $r['text'],
                'parent_id' => $r['parent_id'] === null ? null : (string) $r['parent_id'],
                'root_id' => (string) $r['root_id'],
            ];
        }
        return $out;
    }

    // Soft delete ($action "delete") or restore ("restore") each id. Idempotent:
    // a post already in the wanted state is "unchanged". Every id is logged.
    /** @param list<string> $ids raw ids as sent */
    public function setStatus(array $ids, string $action, bool $dryRun): array
    {
        $to = $action === 'delete' ? 'deleted' : 'active';
        $db = $this->db();
        $results = [];
        $changed = 0;
        $db->begin();
        try {
            foreach ($ids as $raw) {
                $id = norm_uuid($raw);
                if ($id === null) {
                    $results[] = ['id' => $raw, 'result' => 'invalid_id', 'status_before' => null, 'status_after' => null];
                    continue;
                }
                $before = $db->value('SELECT status FROM posts WHERE id = ?', [$id]);
                if ($before === null) {
                    $results[] = ['id' => $id, 'result' => 'not_found', 'status_before' => null, 'status_after' => null];
                    continue;
                }
                $before = $before === 'active' ? 'active' : 'deleted';
                $result = $before === $to ? 'unchanged' : 'changed';
                if ($result === 'changed') {
                    $changed++;
                    if (!$dryRun) {
                        $db->run('UPDATE posts SET status = ? WHERE id = ?', [$to, $id]);
                    }
                }
                $results[] = ['id' => $id, 'result' => $result, 'status_before' => $before, 'status_after' => $to];
            }
            foreach ($results as $r) {
                $this->log($action, (string) $r['id'], $dryRun, (string) $r['result']);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return ['dry_run' => $dryRun, 'changed' => $changed, 'results' => $results];
    }

    // ---- maintenance -------------------------------------------------

    public function maintenance(): array
    {
        $file = (string) $this->cfg['maintenance_file'];
        clearstatcache();
        $on = kssw_maintenance($this->cfg);
        $mtime = $on ? @filemtime($file) : false;
        return [
            'maintenance' => $on,
            'since' => $mtime === false ? null : ts_out(gmdate('Y-m-d\TH:i:s', $mtime) . '.000000Z', $this->tz),
            'database' => is_file((string) $this->cfg['db_path']) ? 'present' : 'missing',
        ];
    }

    public function setMaintenance(bool $on, bool $dryRun): array
    {
        $file = (string) $this->cfg['maintenance_file'];
        if ($file === '') {
            throw new HttpError(409, 'maintenance_file is empty in config.php', [], 'maintenance_unavailable');
        }
        $was = kssw_maintenance($this->cfg);
        if (!$on && $was && !is_file((string) $this->cfg['db_path'])) {
            throw new HttpError(409, 'the database file is missing; upload it before turning maintenance mode off', [], 'database_missing');
        }
        $changed = $was !== $on;
        if ($changed && !$dryRun) {
            if ($on) {
                $dir = dirname($file);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
                if (@file_put_contents($file, 'on since ' . gmdate('c') . "\n") === false) {
                    throw new RuntimeException("cannot create $file");
                }
            } elseif (!@unlink($file)) {
                throw new RuntimeException("cannot delete $file");
            }
            clearstatcache();
        }
        $this->log($on ? 'maintenance_on' : 'maintenance_off', null, $dryRun, $changed ? 'changed' : 'unchanged');
        return ['dry_run' => $dryRun, 'changed' => $changed] + ($dryRun && $changed ? ['maintenance' => $on, 'since' => null, 'database' => $this->maintenance()['database']] : $this->maintenance());
    }

    // ---- backup ------------------------------------------------------

    // A consistent copy of the database in a temporary file next to it (the
    // caller deletes it): VACUUM INTO on SQLite 3.27+, else a file copy
    // inside a read transaction (which keeps writers out in the default
    // rollback-journal mode).
    public function backupFile(): string
    {
        $db = $this->db();
        $path = (string) $this->cfg['db_path'];
        $tmp = dirname($path) . '/backup-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $method = (string) $this->cfg['admin_backup_method'];
        $version = (string) $db->value('SELECT sqlite_version()');
        try {
            if ($method !== 'copy' && version_compare($version, '3.27.0', '>=')) {
                $db->run('VACUUM INTO ?', [$tmp]);
            } else {
                if (strtolower((string) $db->value('PRAGMA journal_mode')) === 'wal') {
                    $db->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                }
                $db->pdo->exec('BEGIN');
                try {
                    $db->value('SELECT COUNT(*) FROM meta');
                    if (!@copy($path, $tmp)) {
                        throw new RuntimeException('cannot copy the database file');
                    }
                } finally {
                    $db->rollback();
                }
            }
            clearstatcache();
            $size = @filesize($tmp);
            if ($size === false || $size === 0) {
                throw new RuntimeException('empty backup');
            }
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
        $this->log('backup', null, false, 'ok');
        return $tmp;
    }

    public function backupName(): string
    {
        return 'kssw-backup-' . (new DateTime('now', $this->tz))->format('Ymd-His') . '.sqlite3';
    }
}

// `php admin.php hash-password`: reads a password on stdin, prints its hash
// for admin_password_hash in config.php (the HTML page's login).
function admin_cli(array $argv): int
{
    if (($argv[1] ?? '') !== 'hash-password') {
        fwrite(STDERR, "usage: php admin.php hash-password   (reads the password on stdin)\n");
        return 2;
    }
    $tty = function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($tty) {
        fwrite(STDERR, 'Password: ');
        @shell_exec('stty -echo 2>/dev/null');
    }
    $line = fgets(STDIN);
    if ($tty) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }
    $pw = rtrim($line === false ? '' : $line, "\r\n");
    if ($pw === '') {
        fwrite(STDERR, "empty password\n");
        return 1;
    }
    echo password_hash($pw, PASSWORD_DEFAULT), "\n";
    return 0;
}
