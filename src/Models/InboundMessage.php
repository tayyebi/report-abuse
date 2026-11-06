<?php

declare(strict_types=1);

namespace App\Models;

/**
 * A message pulled from the intake or response mailbox.
 */
final class InboundMessage extends Model
{
    protected static string $table = 'inbound_messages';

    /**
     * @return list<self>
     */
    public static function forTicket(int $ticketId): array
    {
        return self::where('ticket_id', $ticketId, 'created_at ASC');
    }

    public static function findByMessageId(string $messageId): ?self
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return null;
        }

        return self::findBy('message_id', $messageId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function store(array $data): ?self
    {
        $messageId = trim((string) ($data['message_id'] ?? ''));
        if ($messageId !== '' && self::findByMessageId($messageId) !== null) {
            return null; // already imported
        }

        $id = self::db()->insertReturning(
            'INSERT INTO inbound_messages
                (ticket_id, mailbox, from_address, to_address, subject, body_text, body_html, raw_mime,
                 message_id, in_reply_to, imap_uid, imap_uidvalidity, is_matched, is_processed,
                 error_message, received_at)
             VALUES
                (:ticket_id, :mailbox, :from_address, :to_address, :subject, :body_text, :body_html, :raw_mime,
                 :message_id, :in_reply_to, :imap_uid, :imap_uidvalidity, :is_matched, :is_processed,
                 :error_message, :received_at)
             ON CONFLICT (message_id) DO NOTHING
             RETURNING id',
            [
                'ticket_id' => $data['ticket_id'] ?? null,
                'mailbox' => $data['mailbox'] ?? 'responses',
                'from_address' => $data['from_address'] ?? null,
                'to_address' => $data['to_address'] ?? null,
                'subject' => str_limit((string) ($data['subject'] ?? ''), 490, ''),
                'body_text' => $data['body_text'] ?? null,
                'body_html' => $data['body_html'] ?? null,
                'raw_mime' => $data['raw_mime'] ?? null,
                'message_id' => $messageId === '' ? null : $messageId,
                'in_reply_to' => $data['in_reply_to'] ?? null,
                'imap_uid' => $data['imap_uid'] ?? null,
                'imap_uidvalidity' => $data['imap_uidvalidity'] ?? null,
                'is_matched' => !empty($data['is_matched']) ? 'TRUE' : 'FALSE',
                'is_processed' => !empty($data['is_processed']) ? 'TRUE' : 'FALSE',
                'error_message' => $data['error_message'] ?? null,
                'received_at' => $data['received_at'] ?? date('Y-m-d H:i:s'),
            ]
        );

        if ($id === null) {
            return null;
        }

        return self::find($id);
    }

    /**
     * @return list<self>
     */
    public static function recent(string $mailbox = '', int $limit = 50, bool $onlyUnmatched = false): array
    {
        $conditions = [];
        $params = [];
        if ($mailbox !== '' && $mailbox !== 'all') {
            $conditions[] = 'mailbox = :mailbox';
            $params['mailbox'] = $mailbox;
        }
        if ($onlyUnmatched) {
            $conditions[] = 'is_matched = FALSE';
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $limit = max(1, min(200, $limit));

        $rows = self::db()->fetchAll(
            'SELECT * FROM inbound_messages' . $where . ' ORDER BY received_at DESC NULLS LAST, id DESC LIMIT ' . $limit,
            $params
        );

        return array_map(static fn (array $row): self => self::fromRow($row), $rows);
    }

    /**
     * @return array{intake: int, responses: int, unmatched: int}
     */
    public static function stats(): array
    {
        $row = self::db()->fetch(
            "SELECT
                COUNT(*) FILTER (WHERE mailbox = 'intake') AS intake,
                COUNT(*) FILTER (WHERE mailbox = 'responses') AS responses,
                COUNT(*) FILTER (WHERE is_matched = FALSE AND mailbox = 'responses') AS unmatched
             FROM inbound_messages"
        ) ?? [];

        return [
            'intake' => (int) ($row['intake'] ?? 0),
            'responses' => (int) ($row['responses'] ?? 0),
            'unmatched' => (int) ($row['unmatched'] ?? 0),
        ];
    }

    public function linkTo(int $ticketId): void
    {
        $this->update([
            'ticket_id' => $ticketId,
            'is_matched' => 'TRUE',
            'is_processed' => 'TRUE',
        ]);
    }

    public function from(): string
    {
        return (string) ($this->attributes['from_address'] ?? '');
    }

    public function subject(): string
    {
        return (string) ($this->attributes['subject'] ?? '(no subject)');
    }

    public function bodyText(): string
    {
        return (string) ($this->attributes['body_text'] ?? '');
    }

    public function isMatched(): bool
    {
        return $this->bool('is_matched');
    }
}
