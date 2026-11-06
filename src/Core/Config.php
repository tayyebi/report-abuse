<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuration container.
 *
 * Loads every config/*.php file (each returning an array) into a nested
 * structure addressable with dot notation: Config::get('mail.smtp.host').
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /** @var array<string, mixed> */
    private static array $loadedFrom = [];

    /**
     * @return array<string, mixed>
     */
    public static function load(string $configPath): array
    {
        $files = glob(rtrim($configPath, '/') . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $value = require $file;
            self::$items[$key] = is_array($value) ? $value : [];
            self::$loadedFrom[$key] = $file;
        }

        return self::$items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return self::$items;
        }

        return array_get(self::$items, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$items;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor = $value;
    }
}
