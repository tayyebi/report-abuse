<?php

declare(strict_types=1);

namespace App\Models;

/**
 * A discovered or manually entered abuse contact for a ticket's target.
 */
final class AbuseContact extends Model
{
    protected static string $table = 'abuse_contacts';

    protected static array $jsonColumns = ['raw_lookup'];

    /**
     * @return list<self>
     */
    public static function forTicket(int $ticketId): array
    {
        return self::where('ticket_id', $ticketId, 'is_selected DESC, id ASC');
    }

    public static function selectedFor(int $ticketId): ?self
    {
        $row = self::db()->fetch(
            'SELECT * FROM abuse_contacts WHERE ticket_id = :ticket_id AND is_selected = TRUE ORDER BY id ASC LIMIT 1',
            ['ticket_id' => $ticketId]
        );

        return $row === null ? null : self::fromRow($row);
    }

    public static function add(
        int $ticketId,
        string $email,
        string $source = 'manual',
        ?string $provider = null,
        ?array $rawLookup = null
    ): ?self {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $existing = self::db()->fetch(
            'SELECT * FROM abuse_contacts WHERE ticket_id = :ticket_id AND lower(abuse_email) = :email',
            ['ticket_id' => $ticketId, 'email' => $email]
        );
        if ($existing !== null) {
            return self::fromRow($existing);
        }

        $id = self::db()->insertReturning(
            'INSERT INTO abuse_contacts (ticket_id, provider_name, abuse_email, source, raw_lookup)
             VALUES (:ticket_id, :provider, :email, :source, :raw)
             ON CONFLICT (ticket_id, abuse_email) DO NOTHING
             RETURNING id',
            [
                'ticket_id' => $ticketId,
                'provider' => $provider,
                'email' => $email,
                'source' => $source,
                'raw' => $rawLookup === null ? null : json_encode($rawLookup, JSON_UNESCAPED_SLASHES),
            ]
        );

        if ($id === null) {
            $row = self::db()->fetch(
                'SELECT * FROM abuse_contacts WHERE ticket_id = :ticket_id AND lower(abuse_email) = :email LIMIT 1',
                ['ticket_id' => $ticketId, 'email' => $email]
            );

            return $row === null ? null : self::fromRow($row);
        }

        return self::find($id);
    }

    public static function clearSelection(int $ticketId): void
    {
        self::db()->execute(
            'UPDATE abuse_contacts SET is_selected = FALSE WHERE ticket_id = :ticket_id',
            ['ticket_id' => $ticketId]
        );
    }

    public static function select(int $ticketId, int $contactId): bool
    {
        self::clearSelection($ticketId);
        $updated = self::db()->execute(
            'UPDATE abuse_contacts SET is_selected = TRUE WHERE id = :id AND ticket_id = :ticket_id',
            ['id' => $contactId, 'ticket_id' => $ticketId]
        );

        return $updated > 0;
    }

    public static function remove(int $ticketId, int $contactId): void
    {
        self::db()->execute(
            'DELETE FROM abuse_contacts WHERE id = :id AND ticket_id = :ticket_id',
            ['id' => $contactId, 'ticket_id' => $ticketId]
        );
    }

    public function email(): string
    {
        return (string) ($this->attributes['abuse_email'] ?? '');
    }

    public function provider(): string
    {
        $provider = (string) ($this->attributes['provider_name'] ?? '');

        return $provider !== '' ? $provider : self::domainOf($this->email());
    }

    public function isSelected(): bool
    {
        return $this->bool('is_selected');
    }

    public function source(): string
    {
        return (string) ($this->attributes['source'] ?? 'manual');
    }

    public static function domainOf(string $email): string
    {
        $parts = explode('@', $email);

        return strtolower($parts[1] ?? $email);
    }
}
