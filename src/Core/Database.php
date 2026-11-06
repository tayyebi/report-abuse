<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Thin PDO wrapper (lazy singleton).
 */
final class Database
{
    private static ?Database $instance = null;

    private ?PDO $pdo = null;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            /** @var array<string, mixed> $config */
            $config = Config::get('database', []);
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * Test hook: swap out the connection (used by the test harness).
     */
    public static function usePdo(?PDO $pdo): void
    {
        $instance = self::instance();
        $instance->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (string) ($this->config['port'] ?? '5432');
        $name = (string) ($this->config['name'] ?? 'abuse_desk');
        $user = (string) ($this->config['user'] ?? 'abuse');
        $password = (string) ($this->config['password'] ?? '');
        $sslmode = (string) ($this->config['sslmode'] ?? 'prefer');
        $dsn = (string) ($this->config['dsn'] ?? '');

        if ($dsn === '') {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
            if ($sslmode !== '') {
                $dsn .= ';sslmode=' . $sslmode;
            }
        }

        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->query($sql, $params)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Execute an INSERT and return the new id (append RETURNING id yourself
     * or rely on the sequence).
     *
     * @param array<string|int, mixed> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        $statement = $this->query($sql, $params);
        $id = $statement->fetchColumn();
        if ($id === false || $id === null) {
            $id = $this->pdo()->lastInsertId();
        }

        return (int) $id;
    }

    /**
     * Execute an INSERT ... RETURNING id and return null when no row was
     * returned (e.g. ON CONFLICT DO NOTHING). Unlike lastInsertId() this never
     * reports a stale sequence value from a previous statement.
     *
     * @param array<string|int, mixed> $params
     */
    public function insertReturning(string $sql, array $params = []): ?int
    {
        $id = $this->query($sql, $params)->fetchColumn();
        if ($id === false || $id === null || $id === '') {
            return null;
        }

        return (int) $id;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function now(): string
    {
        return (string) $this->fetchValue('SELECT to_char(now(), \'YYYY-MM-DD HH24:MI:SS\')');
    }

    public function isPostgres(): bool
    {
        return $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
