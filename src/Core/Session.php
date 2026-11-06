<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session + flash storage + CSRF token handling.
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

        if (PHP_SAPI === 'cli') {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }

            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Embedded SAPIs may already have flushed output; changing session
        // parameters then triggers warnings, so only do it when safe.
        if (headers_sent()) {
            @session_start();
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }

            return;
        }

        $name = (string) Config::get('app.session_name', 'abuse_desk_session');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => $https,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        self::start();
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    public static function flash(string $type, string $message): void
    {
        self::start();
        $flashes = $_SESSION['_flash'] ?? [];
        $flashes[] = ['type' => $type, 'message' => $message];
        $_SESSION['_flash'] = $flashes;
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public static function takeFlashes(): array
    {
        self::start();
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        /** @var list<array{type: string, message: string}> $flashes */
        return $flashes;
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf'];
    }

    public static function validateCsrf(?string $token): bool
    {
        self::start();
        $expected = (string) ($_SESSION['_csrf'] ?? '');
        if ($expected === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /*
     * ---------------------------------------------------------------------
     * Admin authentication state
     * ---------------------------------------------------------------------
     */

    public static function loginAdmin(int $adminId, string $email): void
    {
        self::regenerate();
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['admin_email'] = $email;
        $_SESSION['admin_last_seen'] = time();
    }

    public static function adminId(): ?int
    {
        self::start();
        $id = $_SESSION['admin_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public static function adminEmail(): ?string
    {
        self::start();
        $email = $_SESSION['admin_email'] ?? null;

        return $email === null ? null : (string) $email;
    }

    /**
     * Idle timeout enforcement. Returns true when the session is still valid.
     */
    public static function touchAdmin(): bool
    {
        self::start();
        $last = (int) ($_SESSION['admin_last_seen'] ?? 0);
        $lifetime = (int) Config::get('app.session_lifetime', 3600);
        if (self::adminId() === null) {
            return false;
        }
        if ($last > 0 && (time() - $last) > $lifetime) {
            self::logoutAdmin();

            return false;
        }
        $_SESSION['admin_last_seen'] = time();

        return true;
    }

    public static function logoutAdmin(): void
    {
        self::start();
        unset($_SESSION['admin_id'], $_SESSION['admin_email'], $_SESSION['admin_last_seen']);
        self::regenerate();
    }
}
