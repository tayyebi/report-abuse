<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Administrator account: id + email only.
 *
 * No password, no username, no TOTP secret is ever stored — authentication is
 * performed with a single-use code mailed to the address.
 */
final class Admin extends Model
{
    protected static string $table = 'admins';

    public static function findByEmail(string $email): ?self
    {
        return self::findBy('email', self::normaliseEmail($email));
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * @return list<self>
     */
    public static function allAdmins(): array
    {
        return self::all('id ASC');
    }

    public static function createAdmin(string $email): self
    {
        $email = self::normaliseEmail($email);
        $existing = self::findByEmail($email);
        if ($existing !== null) {
            return $existing;
        }

        $id = self::db()->insert(
            'INSERT INTO admins (email) VALUES (:email) RETURNING id',
            ['email' => $email]
        );

        $admin = self::find($id);
        if ($admin === null) {
            throw new \RuntimeException('Failed to create administrator.');
        }

        return $admin;
    }

    public function email(): string
    {
        return (string) ($this->attributes['email'] ?? '');
    }

    public function isActive(): bool
    {
        return $this->bool('is_active');
    }

    public function touchLogin(): void
    {
        $this->update(['last_login_at' => date('Y-m-d H:i:s')]);
    }

    public function setActive(bool $active): void
    {
        $this->update(['is_active' => $active ? 'TRUE' : 'FALSE']);
    }

    /**
     * Seeder: make sure the configured bootstrap administrator exists.
     */
    public static function seedDefault(?string $email = null): ?self
    {
        $email = Admin::normaliseEmail((string) ($email ?? \App\Core\Config::get('app.auth.seed_admin_email', '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return self::createAdmin($email);
    }
}
