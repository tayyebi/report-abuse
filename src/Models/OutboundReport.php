<?php

declare(strict_types=1);

namespace App\Models;

/**
 * A report sent (or attempted) to an abuse desk.
 */
final class OutboundReport extends Model
{
    protected static string $table = 'outbound_reports';

    /**
     * @return list<self>
     */
    public static function forTicket(int $ticketId): array
    {
        return self::where('ticket_id', $ticketId, 'id DESC');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function record(int $ticketId, array $data): self
    {
        $id = self::db()->insert(
            'INSERT INTO outbound_reports
                (ticket_id, abuse_contact_id, format, recipient_email, subject, body, raw_mime,
                 smtp_message_id, send_status, error_message, sent_at)
             VALUES
                (:ticket_id, :abuse_contact_id, :format, :recipient, :subject, :body, :raw_mime,
                 :message_id, :send_status, :error, :sent_at)
             RETURNING id',
            [
                'ticket_id' => $ticketId,
                'abuse_contact_id' => $data['abuse_contact_id'] ?? null,
                'format' => $data['format'] ?? 'arf',
                'recipient' => $data['recipient_email'] ?? '',
                'subject' => $data['subject'] ?? '',
                'body' => $data['body'] ?? '',
                'raw_mime' => $data['raw_mime'] ?? '',
                'message_id' => $data['smtp_message_id'] ?? null,
                'send_status' => $data['send_status'] ?? 'draft',
                'error' => $data['error_message'] ?? null,
                'sent_at' => $data['sent_at'] ?? null,
            ]
        );

        $report = self::find($id);
        if ($report === null) {
            throw new \RuntimeException('Failed to record outbound report.');
        }

        return $report;
    }

    public function markSent(?string $messageId): void
    {
        $this->update([
            'send_status' => 'sent',
            'smtp_message_id' => $messageId,
            'sent_at' => date('Y-m-d H:i:s'),
            'error_message' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'send_status' => 'failed',
            'error_message' => str_limit($error, 900, ''),
        ]);
    }

    public function recipient(): string
    {
        return (string) ($this->attributes['recipient_email'] ?? '');
    }

    public function subject(): string
    {
        return (string) ($this->attributes['subject'] ?? '');
    }

    public function body(): string
    {
        return (string) ($this->attributes['body'] ?? '');
    }

    public function rawMime(): string
    {
        return (string) ($this->attributes['raw_mime'] ?? '');
    }

    public function isSent(): bool
    {
        return (string) ($this->attributes['send_status'] ?? '') === 'sent';
    }

    /**
     * @return list<self>
     */
    public static function sentForReference(string $messageId): array
    {
        if (trim($messageId) === '') {
            return [];
        }

        return self::where('smtp_message_id', trim($messageId), 'id DESC', 5);
    }
}
