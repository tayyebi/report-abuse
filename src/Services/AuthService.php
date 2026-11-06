<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Session;
use App\Models\Admin;
use App\Models\LoginCode;
use App\Models\TicketEvent;

/**
 * Passwordless authentication.
 *
 * There is no password to store: the administrator enters their e-mail
 * address, receives a single-use code, and enters that code. Codes are stored
 * only as a password_hash digest, expire quickly, allow a limited number of
 * attempts and are invalidated as soon as a new one is issued.
 */
final class AuthService
{
    public function __construct(private readonly NotificationService $notifier = new NotificationService())
    {
    }

    /**
     * @return array{ok: bool, error: ?string, debug_code: ?string}
     */
    public function requestCode(string $email, string $ip = ''): array
    {
        $email = Admin::normaliseEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid e-mail address.', 'debug_code' => null];
        }

        $window = (int) Config::get('app.auth.request_window', 900);
        $max = (int) Config::get('app.auth.request_max', 5);
        if ($max > 0 && LoginCode::recentRequests($email, $window) >= $max) {
            return [
                'ok' => false,
                'error' => 'Too many sign-in codes were requested for this address. Please wait a few minutes and try again.',
                'debug_code' => null,
            ];
        }

        $admin = Admin::findByEmail($email);
        if ($admin === null || !$admin->isActive()) {
            // Do not reveal whether the address is registered.
            return ['ok' => true, 'error' => null, 'debug_code' => null];
        }

        $ttl = max(60, (int) Config::get('app.auth.code_ttl', 600));
        $issued = LoginCode::issue((int) $admin->id(), $ip !== '' ? $ip : null);

        $result = $this->notifier->sendLoginCode($admin, $issued['code'], (int) ceil($ttl / 60), $ip);
        if (!$result['ok']) {
            return [
                'ok' => false,
                'error' => 'The sign-in code could not be sent. Please try again later or contact the operator.',
                'debug_code' => null,
            ];
        }

        Session::put('pending_admin_email', $email);
        Session::put('pending_admin_at', time());

        return [
            'ok' => true,
            'error' => null,
            'debug_code' => (bool) Config::get('app.debug', false) ? $issued['code'] : null,
        ];
    }

    public function verifyCode(string $email, string $code): ?Admin
    {
        $admin = Admin::findByEmail($email);
        if ($admin === null || !$admin->isActive()) {
            return null;
        }

        if (!LoginCode::verify((int) $admin->id(), $code)) {
            return null;
        }

        $admin->touchLogin();
        Session::loginAdmin((int) $admin->id(), $admin->email());
        Session::forget('pending_admin_email');
        Session::forget('pending_admin_at');

        return $admin;
    }

    public function logout(): void
    {
        Session::logoutAdmin();
    }

    /**
     * @return list<Admin>
     */
    public function admins(): array
    {
        return Admin::allAdmins();
    }

    public function addAdmin(string $email): ?Admin
    {
        $email = Admin::normaliseEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return Admin::createAdmin($email);
    }

    public function toggleAdmin(int $id, bool $active): bool
    {
        if (Session::adminId() === $id && !$active) {
            return false; // never let an admin lock themselves out
        }

        $admin = Admin::find($id);
        if ($admin === null) {
            return false;
        }
        $admin->setActive($active);

        return true;
    }

    public function findAdmin(int $id): ?Admin
    {
        return Admin::find($id);
    }

    /**
     * Helper used when a ticket is opened by an admin (audit trail).
     */
    public function logAdminAction(int $ticketId, string $summary, array $details = []): void
    {
        TicketEvent::log($ticketId, 'admin_action', $summary, 'admin', $details);
    }
}
