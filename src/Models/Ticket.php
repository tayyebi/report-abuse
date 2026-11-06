<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use PDOException;

/**
 * The core ticket entity.
 */
final class Ticket extends Model
{
    protected static string $table = 'tickets';

    protected static array $jsonColumns = ['enrichment_data'];

    public const STATUS_NEW = 'new';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REPORTED = 'reported';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CLOSED = 'closed';

    /**
     * @param array<string, mixed> $data
     */
    public static function createTicket(array $data): self
    {
        $now = date('Y-m-d H:i:s');

        $payload = [
            'ticket_number' => $data['ticket_number'] ?? null,
            'status' => $data['status'] ?? self::STATUS_NEW,
            'abuse_type' => $data['abuse_type'] ?? 'other',
            'reported_url' => $data['reported_url'] ?? null,
            'reported_domain' => $data['reported_domain'] ?? null,
            'reported_ip' => $data['reported_ip'] ?? null,
            'reporter_email' => $data['reporter_email'] ?? '',
            'reporter_name' => $data['reporter_name'] ?? null,
            'reporter_ip' => $data['reporter_ip'] ?? null,
            'reporter_token' => $data['reporter_token'] ?? bin2hex(random_bytes(32)),
            'description' => $data['description'] ?? null,
            'source' => $data['source'] ?? 'web',
            'source_message_id' => $data['source_message_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // The ticket number is date based; retry if a concurrent request wins.
        $attempts = 0;
        while (true) {
            $attempts++;
            $number = $payload['ticket_number'] ?? self::nextTicketNumber();
            $payload['ticket_number'] = $number;

            try {
                $id = self::db()->insert(
                    'INSERT INTO tickets
                        (ticket_number, status, abuse_type, reported_url, reported_domain, reported_ip,
                         reporter_email, reporter_name, reporter_ip, reporter_token, description,
                         source, source_message_id, created_at, updated_at)
                     VALUES
                        (:ticket_number, :status, :abuse_type, :reported_url, :reported_domain, :reported_ip,
                         :reporter_email, :reporter_name, :reporter_ip, :reporter_token, :description,
                         :source, :source_message_id, :created_at, :updated_at)
                     RETURNING id',
                    $payload
                );

                $ticket = self::find($id);
                if ($ticket === null) {
                    throw new \RuntimeException('Ticket creation failed.');
                }

                return $ticket;
            } catch (PDOException $e) {
                if ($attempts < 6 && str_contains(strtolower($e->getMessage()), 'duplicate key')) {
                    unset($payload['ticket_number']);
                    usleep(random_int(20_000, 120_000));
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function nextTicketNumber(?string $date = null): string
    {
        $date = $date ?? date('Ymd');
        $prefix = 'ABR-' . $date . '-';

        $count = (int) self::db()->fetchValue(
            'SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE :prefix',
            ['prefix' => $prefix . '%']
        );

        $sequence = $count + 1;
        $candidate = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

        // Guard against gaps left by deleted rows.
        while (self::findBy('ticket_number', $candidate) !== null && $sequence < 9999) {
            $sequence++;
            $candidate = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    public static function findByToken(string $token): ?self
    {
        if ($token === '' || strlen($token) < 20) {
            return null;
        }

        return self::findBy('reporter_token', $token);
    }

    public static function findByNumber(string $number): ?self
    {
        return self::findBy('ticket_number', trim($number));
    }

    public static function findBySourceMessageId(string $messageId): ?self
    {
        if (trim($messageId) === '') {
            return null;
        }

        return self::findBy('source_message_id', trim($messageId));
    }

    /**
     * @param array{status?: string, abuse_type?: string, source?: string, q?: string} $filters
     * @return list<self>
     */
    public static function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::buildFilters($filters);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT * FROM tickets' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        return array_map(
            static fn (array $row): self => self::fromRow($row),
            self::db()->fetchAll($sql, $params)
        );
    }

    /**
     * @param array{status?: string, abuse_type?: string, source?: string, q?: string} $filters
     */
    public static function searchCount(array $filters = []): int
    {
        [$where, $params] = self::buildFilters($filters);

        return (int) self::db()->fetchValue('SELECT COUNT(*) FROM tickets' . $where, $params);
    }

    /**
     * @param array{status?: string, abuse_type?: string, source?: string, q?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildFilters(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'open') {
                $conditions[] = "status IN ('new', 'approved', 'reported')";
            } else {
                $conditions[] = 'status = :status';
                $params['status'] = $filters['status'];
            }
        }

        if (!empty($filters['abuse_type']) && $filters['abuse_type'] !== 'all') {
            $conditions[] = 'abuse_type = :abuse_type';
            $params['abuse_type'] = $filters['abuse_type'];
        }

        if (!empty($filters['source']) && $filters['source'] !== 'all') {
            $conditions[] = 'source = :source';
            $params['source'] = $filters['source'];
        }

        if (!empty($filters['q'])) {
            $conditions[] = '(ticket_number ILIKE :q OR reported_domain ILIKE :q OR reporter_email ILIKE :q
                              OR reported_url ILIKE :q OR reported_ip ILIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }

    /**
     * @return array<string, int>
     */
    public static function countsByStatus(): array
    {
        $rows = self::db()->fetchAll('SELECT status, COUNT(*) AS total FROM tickets GROUP BY status');
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public static function countAll(): int
    {
        return (int) self::db()->fetchValue('SELECT COUNT(*) FROM tickets');
    }

    /**
     * Tickets that still need enrichment (used by lazy piggyback processing).
     *
     * @return list<self>
     */
    public static function needingEnrichment(int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        $rows = self::db()->fetchAll(
            'SELECT * FROM tickets
             WHERE enrichment_done = FALSE AND status IN (\'new\', \'approved\')
             ORDER BY created_at ASC LIMIT ' . $limit
        );

        return array_map(static fn (array $row): self => self::fromRow($row), $rows);
    }

    /**
     * Tickets reported in the last N days with no reply yet (dashboard widget).
     *
     * @return list<self>
     */
    public static function awaitingResponse(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $rows = self::db()->fetchAll(
            'SELECT t.* FROM tickets t
             WHERE t.status = \'reported\'
               AND NOT EXISTS (SELECT 1 FROM inbound_messages m WHERE m.ticket_id = t.id AND m.is_matched = TRUE)
             ORDER BY t.reported_at DESC NULLS LAST LIMIT ' . $limit
        );

        return array_map(static fn (array $row): self => self::fromRow($row), $rows);
    }

    /*
     * ---------------------------------------------------------------------
     * Accessors
     * ---------------------------------------------------------------------
     */

    public function number(): string
    {
        return (string) ($this->attributes['ticket_number'] ?? '');
    }

    public function token(): string
    {
        return (string) ($this->attributes['reporter_token'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->attributes['status'] ?? self::STATUS_NEW);
    }

    public function statusLabel(): string
    {
        $statuses = (array) Config::get('app.statuses', []);

        return (string) ($statuses[$this->status()] ?? ucfirst($this->status()));
    }

    public function abuseType(): string
    {
        return (string) ($this->attributes['abuse_type'] ?? 'other');
    }

    public function abuseTypeLabel(): string
    {
        $types = (array) Config::get('app.abuse_types', []);

        return (string) ($types[$this->abuseType()] ?? ucfirst($this->abuseType()));
    }

    public function domain(): ?string
    {
        $domain = $this->attributes['reported_domain'] ?? null;

        return $domain === null || $domain === '' ? null : (string) $domain;
    }

    public function ip(): ?string
    {
        $ip = $this->attributes['reported_ip'] ?? null;

        return $ip === null || $ip === '' ? null : (string) $ip;
    }

    public function url(): ?string
    {
        $url = $this->attributes['reported_url'] ?? null;

        return $url === null || $url === '' ? null : (string) $url;
    }

    public function target(): string
    {
        return $this->domain() ?? $this->ip() ?? $this->url() ?? 'unknown target';
    }

    public function isOpen(): bool
    {
        return in_array($this->status(), [self::STATUS_NEW, self::STATUS_APPROVED, self::STATUS_REPORTED], true);
    }

    public function publicUrl(): string
    {
        return rtrim((string) Config::get('app.url', ''), '/') . '/ticket/' . $this->token();
    }

    public function shortDescription(int $limit = 140): string
    {
        $text = trim((string) ($this->attributes['description'] ?? ''));

        return $text === '' ? '—' : str_limit($text, $limit);
    }

    /*
     * ---------------------------------------------------------------------
     * Related records
     * ---------------------------------------------------------------------
     */

    /**
     * @return list<Evidence>
     */
    public function evidence(): array
    {
        return Evidence::forTicket((int) $this->id());
    }

    /**
     * @return list<AbuseContact>
     */
    public function contacts(): array
    {
        return AbuseContact::forTicket((int) $this->id());
    }

    /**
     * @return list<OutboundReport>
     */
    public function reports(): array
    {
        return OutboundReport::forTicket((int) $this->id());
    }

    /**
     * @return list<InboundMessage>
     */
    public function inboundMessages(): array
    {
        return InboundMessage::forTicket((int) $this->id());
    }

    /**
     * @return list<TicketEvent>
     */
    public function events(): array
    {
        return TicketEvent::forTicket((int) $this->id());
    }

    /**
     * @return list<AbuseContact>
     */
    public function suggestedContacts(): array
    {
        $contacts = $this->contacts();
        $selected = array_values(array_filter($contacts, static fn (AbuseContact $c): bool => $c->isSelected()));

        return $selected === [] ? $contacts : $selected;
    }

    /**
     * @return array<string, mixed>
     */
    public function enrichment(): array
    {
        $data = $this->__get('enrichment_data');

        return is_array($data) ? $data : [];
    }

    public function enrichmentValue(string $key, mixed $default = null): mixed
    {
        return $this->enrichment()[$key] ?? $default;
    }

    public function isEnriched(): bool
    {
        return $this->bool('enrichment_done');
    }

    public function markEnriched(array $data): void
    {
        $this->update([
            'enrichment_data' => $data,
            'enrichment_done' => 'TRUE',
            'enrichment_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function touchUpdated(): void
    {
        $this->update(['updated_at' => date('Y-m-d H:i:s')]);
    }
}
