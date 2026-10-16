<?php
// SQLite connection, schema install, keyed hashes.

declare(strict_types=1);

final class Db
{
    public const SCHEMA_VERSION = '1';

    /** @var PDO */
    public $pdo;
    /** @var string binary HMAC key */
    private $secret;

    public function __construct(string $path, string $journalMode = '')
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create database directory $dir");
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        if ($journalMode !== '') {
            $this->pdo->exec('PRAGMA journal_mode = ' . $journalMode);
        }
        $this->secret = $this->ensureSchema();
    }

    // Tables are created on first access, so a host without shell access
    // only needs the files.
    private function ensureSchema(): string
    {
        try {
            $v = $this->value("SELECT value FROM meta WHERE name = 'schema_version'");
            $s = $this->value("SELECT value FROM meta WHERE name = 'secret'");
            if ($v === self::SCHEMA_VERSION && is_string($s) && $s !== '') {
                return (string) hex2bin($s);
            }
        } catch (PDOException $e) {
            // Not installed yet.
        }
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('schema.sql missing');
        }
        $this->pdo->exec($sql);
        $this->run("INSERT OR IGNORE INTO meta (name, value) VALUES ('secret', ?)", [bin2hex(random_bytes(32))]);
        return (string) hex2bin((string) $this->value("SELECT value FROM meta WHERE name = 'secret'"));
    }

    private function stmt(string $sql, array $params): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            if (is_int($p)) {
                $st->bindValue($i, $p, PDO::PARAM_INT);
            } elseif ($p === null) {
                $st->bindValue($i, null, PDO::PARAM_NULL);
            } else {
                $st->bindValue($i, (string) $p, PDO::PARAM_STR);
            }
            $i++;
        }
        $st->execute();
        return $st;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->stmt($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->stmt($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return string|int|float|null */
    public function value(string $sql, array $params = [])
    {
        $v = $this->stmt($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function run(string $sql, array $params = []): void
    {
        $this->stmt($sql, $params);
    }

    // Runs a write and returns the number of rows it changed.
    public function change(string $sql, array $params = []): int
    {
        return $this->stmt($sql, $params)->rowCount();
    }

    // IMMEDIATE takes the write lock up front, so a check-then-insert (the
    // post cooldown) cannot interleave with another request.
    public function begin(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
    }

    public function commit(): void
    {
        $this->pdo->exec('COMMIT');
    }

    public function rollback(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (PDOException $e) {
            // no transaction open
        }
    }

    // Keyed hash of an IP ("ip") or a client id ("cid"). Raw values are never
    // stored. Same construction as every other implementation of schema.sql.
    public function key(string $kind, string $value): string
    {
        return substr(hash_hmac('sha256', $kind . ':' . $value, $this->secret), 0, 32);
    }
}
