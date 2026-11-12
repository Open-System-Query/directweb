<?php
declare(strict_types=1);
namespace DirectWeb\Core;
final class Database
{
    public readonly \PDO $pdo;
    public function __construct(string $file)
    {
        Files::mkdir(dirname($file));
        $this->pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=10000; PRAGMA synchronous=FULL;');
    }
    public function execute(string $sql, array $params = []): \PDOStatement { $s = $this->pdo->prepare($sql); $s->execute($params); return $s; }
    public function one(string $sql, array $params = []): ?array { return $this->execute($sql, $params)->fetch() ?: null; }
    public function all(string $sql, array $params = []): array { return $this->execute($sql, $params)->fetchAll(); }
    public function transaction(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $result = $callback(); $this->pdo->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }
}
