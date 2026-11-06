<?php

declare(strict_types=1);

/**
 * PostgreSQL connection settings.
 *
 * Set DB_DSN to override the individual parts (useful for managed services).
 */
return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'name' => env('DB_NAME', 'abuse_desk'),
    'user' => env('DB_USER', 'abuse'),
    'password' => env('DB_PASSWORD', ''),
    'sslmode' => env('DB_SSLMODE', 'prefer'),
    'dsn' => env('DB_DSN', ''),
];
