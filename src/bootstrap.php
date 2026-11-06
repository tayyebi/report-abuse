<?php

declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Deliberately dependency-free: a tiny PSR-4 autoloader instead of Composer.
 */

require_once __DIR__ . '/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/*
 * Minimal .env loader (KEY=VALUE lines, optional "quotes", # comments).
 * Real environment variables always win.
 */
$dotenv = BASE_PATH . '/.env';
if (is_file($dotenv) && is_readable($dotenv)) {
    foreach (file($dotenv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '') {
            continue;
        }
        $value = trim($value);
        if (strlen($value) > 1
            && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")))
        ) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

date_default_timezone_set((string) env('APP_TIMEZONE', 'UTC'));

// Make sure runtime directories exist and are writable.
foreach (['storage', 'storage/uploads', 'storage/outbox', 'storage/logs'] as $dir) {
    $path = base_path($dir);
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

return App\Core\Config::load(BASE_PATH . '/config');
