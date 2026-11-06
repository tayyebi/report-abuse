<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Key/value store for the "no cron" piggyback scheduling.
 */
final class SystemState
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = static::db()->fetchValue('SELECT value FROM system_state WHERE key = :key', ['key' => $key]);

        return $value === null ? $default : (string) $value;
    }

    public static function set(string $key, ?string $value): void
    {
        static::db()->execute(
            'INSERT INTO system_state (key, value, updated_at) VALUES (:key, :value, NOW())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()',
            ['key' => $key, 'value' => $value ?? '']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function getJson(string $key): array
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function setJson(string $key, array $value): void
    {
        self::set($key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Has the given key been updated within $seconds? Uses the database clock
     * so container/host clock skew cannot break the schedule.
     */
    public static function isFresh(string $key, int $seconds): bool
    {
        $value = static::db()->fetchValue(
            'SELECT (NOW() - value::timestamptz) < (:secs || \' seconds\')::interval
             FROM system_state WHERE key = :key',
            ['key' => $key, 'secs' => (string) max(1, $seconds)]
        );

        if ($value === null) {
            return false;
        }

        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    public static function touch(string $key): void
    {
        self::set($key, date('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $rows = static::db()->fetchAll('SELECT key, value FROM system_state ORDER BY key');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = (string) $row['value'];
        }

        return $out;
    }

    private static function db(): \App\Core\Database
    {
        return \App\Core\Database::instance();
    }
}
