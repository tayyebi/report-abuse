<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Session;

/**
 * Audit trail / timeline entries.
 */
final class TicketEvent extends Model
{
    protected static string $table = 'ticket_events';

    protected static array $jsonColumns = ['details'];

    /**
     * @return list<self>
     */
    public static function forTicket(int $ticketId): array
    {
        return self::where('ticket_id', $ticketId, 'created_at ASC, id ASC');
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function log(
        int $ticketId,
        string $eventType,
        string $summary,
        string $actor = 'system',
        array $details = []
    ): self {
        $actorEmail = $actor === 'admin' ? Session::adminEmail() : null;

        $id = self::db()->insert(
            'INSERT INTO ticket_events (ticket_id, event_type, actor, actor_email, summary, details)
             VALUES (:ticket_id, :event_type, :actor, :actor_email, :summary, :details)
             RETURNING id',
            [
                'ticket_id' => $ticketId,
                'event_type' => $eventType,
                'actor' => $actor,
                'actor_email' => $actorEmail,
                'summary' => str_limit($summary, 490, ''),
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );

        $event = self::find($id);
        if ($event === null) {
            throw new \RuntimeException('Failed to log ticket event.');
        }

        return $event;
    }

    public function summary(): string
    {
        return (string) ($this->attributes['summary'] ?? '');
    }

    public function type(): string
    {
        return (string) ($this->attributes['event_type'] ?? '');
    }

    public function actor(): string
    {
        return (string) ($this->attributes['actor'] ?? 'system');
    }

    public function createdAt(): ?string
    {
        $value = $this->attributes['created_at'] ?? null;

        return $value === null ? null : (string) $value;
    }
}
