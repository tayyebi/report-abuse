<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;

/**
 * Single-use, short-lived admin login codes.
 *
 * Only a password_hash() digest of the code is persisted; the plain code
 * exists solely in the email that was sent to the administrator.
 */
final class LoginCode extends Model
{
    protected static string $table = 'admin_login_codes';

    /**
     * Generate a numeric code of the configured length.
     */
    public static function generateCode(): string
    {
        $length = max(4, min(10, (int) Config::get('app.auth.code_length', 6)));
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Invalidate outstanding codes and create a fresh one.
     *
     * @return array{code: string, expires_at: string}
     */
    public static function issue(int $adminId, ?string $ip = null): array
    {
        $ttl = max(60, (int) Config::get('app.auth.code_ttl', 600));
        $code = self::generateCode();
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        self::db()->execute(
            'UPDATE admin_login_codes SET used_at = NOW() WHERE admin_id = :admin_id AND used_at IS NULL',
            ['admin_id' => $adminId]
        );

        self::db()->insert(
            'INSERT INTO admin_login_codes (admin_id, code_hash, requested_ip, expires_at)
             VALUES (:admin_id, :code_hash, :ip, :expires_at) RETURNING id',
            [
                'admin_id' => $adminId,
                'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                'ip' => $ip,
                'expires_at' => $expiresAt,
            ]
        );

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * Number of codes requested for this e-mail address lately (rate limiting).
     */
    public static function recentRequests(string $email, int $windowSeconds): int
    {
        $value = self::db()->fetchValue(
            'SELECT COUNT(*) FROM admin_login_codes c
             JOIN admins a ON a.id = c.admin_id
             WHERE a.email = :email AND c.created_at > NOW() - (:secs || \' seconds\')::interval',
            ['email' => Admin::normaliseEmail($email), 'secs' => (string) max(1, $windowSeconds)]
        );

        return (int) $value;
    }

    public static function latestFor(int $adminId): ?self
    {
        $row = self::db()->fetch(
            'SELECT * FROM admin_login_codes
             WHERE admin_id = :admin_id AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1',
            ['admin_id' => $adminId]
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Verify a submitted code. Returns true exactly once per issued code.
     */
    public static function verify(int $adminId, string $code): bool
    {
        $maxAttempts = max(1, (int) Config::get('app.auth.max_attempts', 5));
        $record = self::latestFor($adminId);
        if ($record === null) {
            return false;
        }

        $attempts = (int) $record->get('attempts', 0);
        if ($attempts >= $maxAttempts) {
            return false;
        }

        $hash = (string) $record->get('code_hash', '');
        $code = preg_replace('/\D+/', '', $code) ?? '';

        if ($code === '' || !password_verify($code, $hash)) {
            self::db()->execute(
                'UPDATE admin_login_codes SET attempts = attempts + 1 WHERE id = :id',
                ['id' => $record->id()]
            );

            return false;
        }

        $updated = self::db()->execute(
            'UPDATE admin_login_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL',
            ['id' => $record->id()]
        );

        return $updated === 1;
    }

    /**
     * Remove stale rows (housekeeping, called with the seeder/migrate command).
     */
    public static function prune(): int
    {
        return self::db()->execute(
            'DELETE FROM admin_login_codes WHERE created_at < NOW() - INTERVAL \'30 days\''
        );
    }
}
