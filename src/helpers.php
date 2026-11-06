<?php

declare(strict_types=1);

/**
 * Global helpers.
 *
 * Loaded before the autoloader so configuration files may use env().
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment variable with a fallback.
     */
    function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('env_int')) {
    function env_int(string $key, int $default = 0): int
    {
        $value = env($key);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }
}

if (!function_exists('e')) {
    /**
     * HTML-escape a value for safe output.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('str_slug')) {
    function str_slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}

if (!function_exists('array_get')) {
    function array_get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }
}

if (!function_exists('format_bytes')) {
    function format_bytes(?int $bytes): string
    {
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $i = -1;
        do {
            $bytes /= 1024;
            $i++;
        } while ($bytes >= 1024 && $i < count($units) - 1);

        return round($bytes, 1) . ' ' . $units[$i];
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $timestamp): string
    {
        if (!$timestamp) {
            return 'never';
        }
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return 'unknown';
        }
        $diff = time() - $ts;
        if ($diff < 0) {
            return 'in ' . time_span(abs($diff));
        }

        return $diff < 45 ? 'just now' : time_span($diff) . ' ago';
    }
}

if (!function_exists('time_span')) {
    function time_span(int $seconds): string
    {
        if ($seconds < 90) {
            return $seconds . 's';
        }
        if ($seconds < 5400) {
            return round($seconds / 60) . 'm';
        }
        if ($seconds < 172800) {
            return round($seconds / 3600) . 'h';
        }

        return round($seconds / 86400) . 'd';
    }
}

if (!function_exists('str_limit')) {
    function str_limit(?string $value, int $limit = 120, string $end = '…'): string
    {
        $value = (string) $value;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . $end : $value;
        }

        return strlen($value) > $limit ? substr($value, 0, $limit - strlen($end)) . $end : $value;
    }
}

if (!function_exists('status_class')) {
    /**
     * CSS class for a ticket status badge.
     */
    function status_class(string $status): string
    {
        $clean = preg_replace('/[^a-z0-9\-]/', '', strtolower($status));

        return 'badge-' . ($clean === '' ? 'other' : $clean);
    }
}

if (!function_exists('abuse_icon')) {
    function abuse_icon(string $type): string
    {
        return [
            'phishing' => '🎣',
            'malware' => '🦠',
            'spam' => '📧',
            'fraud' => '💳',
            'botnet' => '🤖',
            'other' => '⚠️',
        ][$type] ?? '⚠️';
    }
}

if (!function_exists('selected_if')) {
    function selected_if(bool $condition): string
    {
        return $condition ? ' selected' : '';
    }
}
