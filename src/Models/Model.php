<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Minimal ActiveRecord-ish base class. Instances are lightweight row wrappers.
 */
abstract class Model
{
    protected static string $table = '';

    protected static string $primaryKey = 'id';

    /** @var list<string> columns that hold JSONB payloads */
    protected static array $jsonColumns = [];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    protected static function db(): Database
    {
        return Database::instance();
    }

    public static function table(): string
    {
        return static::$table;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        return new static($row);
    }

    public function __get(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (in_array($key, static::$jsonColumns, true) && is_string($value)) {
            $decoded = json_decode($value, true);
            $this->attributes[$key] = is_array($decoded) ? $decoded : [];

            return $this->attributes[$key];
        }

        return $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function id(): ?int
    {
        $id = $this->attributes[static::$primaryKey] ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->__get($key);

        return $value ?? $default;
    }

    public function bool(string $key): bool
    {
        $value = $this->attributes[$key] ?? false;

        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    public static function find(int|string|null $id): ?static
    {
        if ($id === null || $id === '') {
            return null;
        }
        $row = static::db()->fetch(
            sprintf('SELECT * FROM %s WHERE %s = :id', static::$table, static::$primaryKey),
            ['id' => $id]
        );

        return $row === null ? null : static::fromRow($row);
    }

    public static function findBy(string $column, mixed $value): ?static
    {
        $row = static::db()->fetch(
            sprintf('SELECT * FROM %s WHERE %s = :value LIMIT 1', static::$table, $column),
            ['value' => $value]
        );

        return $row === null ? null : static::fromRow($row);
    }

    /**
     * @return list<static>
     */
    public static function where(string $column, mixed $value, string $orderBy = 'id ASC', ?int $limit = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE %s = :value ORDER BY %s', static::$table, $column, $orderBy);
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        return array_map(
            static fn (array $row): static => static::fromRow($row),
            static::db()->fetchAll($sql, ['value' => $value])
        );
    }

    /**
     * @return list<static>
     */
    public static function all(string $orderBy = 'id DESC', ?int $limit = null): array
    {
        $sql = sprintf('SELECT * FROM %s ORDER BY %s', static::$table, $orderBy);
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        return array_map(
            static fn (array $row): static => static::fromRow($row),
            static::db()->fetchAll($sql)
        );
    }

    /**
     * Update a set of columns on the current row.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        $assignments = [];
        $params = ['id' => $this->id()];
        foreach ($values as $column => $value) {
            $placeholder = 'v_' . $column;
            $cast = '';
            if (in_array($column, static::$jsonColumns, true) && is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $cast = '::jsonb';
            }
            $assignments[] = sprintf('%s = :%s%s', $column, $placeholder, $cast);
            $params[$placeholder] = $value;
            $this->attributes[$column] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :id',
            static::$table,
            implode(', ', $assignments),
            static::$primaryKey
        );

        return static::db()->execute($sql, $params) >= 0;
    }

    public function refresh(): void
    {
        $fresh = static::find($this->id());
        if ($fresh !== null) {
            $this->attributes = $fresh->toArray();
        }
    }
}
