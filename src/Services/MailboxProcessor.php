<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\InboundMessage;
use App\Models\OutboundReport;
use App\Models\SystemState;
use App\Models\Ticket;
use App\Models\TicketEvent;

/**
 * Pulls the intake and response mailboxes and turns messages into tickets or
 * ticket replies. Invoked from admin page loads ("piggyback processing").
 */
final class MailboxProcessor
{
    public function __construct(
        private readonly TicketService $tickets = new TicketService(),
        private readonly ?ImapClient $imap = null
    ) {
    }

    /**
     * @return array{ok: bool, fetched: int, created: int, skipped: int, errors: list<string>}
     */
    public function checkIntake(bool $force = false): array
    {
        return $this->run('intake', $force);
    }

    /**
     * @return array{ok: bool, fetched: int, created: int, skipped: int, errors: list<string>}
     */
    public function checkResponses(bool $force = false): array
    {
        return $this->run('responses', $force);
    }

    /**
     * @return array{ok: bool, fetched: int, created: int, skipped: int, errors: list<string>}
     */
    private function run(string $kind, bool $force): array
    {
        $result = ['ok' => false, 'fetched' => 0, 'created' => 0, 'skipped' => 0, 'errors' => []];

        $config = (array) Config::get('mail.imap', []);
        if (($config['host'] ?? '') === '') {
            $result['errors'][] = 'IMAP is not configured (set IMAP_HOST).';

            return $result;
        }

        $mailbox = (string) ($config[$kind === 'intake' ? 'intake_mailbox' : 'response_mailbox'] ?? 'INBOX');
        $statePrefix = $kind === 'intake' ? 'last_intake' : 'last_response';

        $client = $this->imap ?? new ImapClient($config);

        try {
            $state = $client->connect($mailbox);
            $uidvalidity = (int) ($state['uidvalidity'] ?? 1);

            $storedValidity = (int) (SystemState::get($statePrefix . '_uidvalidity') ?? 0);
            $lastUid = (int) (SystemState::get($statePrefix . '_uid') ?? 0);
            if ($storedValidity !== 0 && $storedValidity !== $uidvalidity) {
                $lastUid = 0; // mailbox was recreated; rescan
            }

            $limit = (int) ($config['batch_limit'] ?? 25);
            $messages = $client->fetchNewMessages($lastUid, $limit);

            $maxUid = $lastUid;
            foreach ($messages as $message) {
                $result['fetched']++;
                $maxUid = max($maxUid, (int) $message['uid']);

                try {
                    $processed = $kind === 'intake'
                        ? $this->processIntake($message, $mailbox, $uidvalidity)
                        : $this->processResponse($message, $mailbox, $uidvalidity);

                    if ($processed === 'created') {
                        $result['created']++;
                    } elseif ($processed === 'matched') {
                        $result['created']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $result['errors'][] = 'UID ' . $message['uid'] . ': ' . $e->getMessage();
                }
            }

            SystemState::set($statePrefix . '_uid', (string) $maxUid);
            SystemState::set($statePrefix . '_uidvalidity', (string) $uidvalidity);
            SystemState::touch($statePrefix . '_check');
            SystemState::setJson($statePrefix . '_report', [
                'at' => date('c'),
                'fetched' => $result['fetched'],
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'errors' => array_slice($result['errors'], 0, 5),
            ]);

            $result['ok'] = true;
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
            SystemState::setJson($statePrefix . '_report', [
                'at' => date('c'),
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $client->logout();
        }

        return $result;
    }

    /**
     * @param array{uid: int, raw: string, internal_date: ?string} $message
     */
    private function processIntake(array $message, string $mailbox, int $uidvalidity): string
    {
        $parsed = EmailParser::parse($message['raw'], $message['internal_date']);

        $messageId = (string) ($parsed['message_id'] ?? '');
        if ($messageId !== '' && InboundMessage::findByMessageId($messageId) !== null) {
            return 'skipped';
        }

        // Replies belong to the response mailbox.
        if (($parsed['in_reply_to'] ?? '') !== '' || stripos((string) $parsed['subject'], 're:') === 0) {
            return 'skipped';
        }

        $ticket = $this->tickets->createFromEmail($parsed, 'intake');

        InboundMessage::store([
            'ticket_id' => $ticket?->id(),
            'mailbox' => 'intake',
            'from_address' => $parsed['from'] ?? null,
            'to_address' => $parsed['to'] ?? null,
            'subject' => $parsed['subject'] ?? '',
            'body_text' => $parsed['body_text'] ?? '',
            'body_html' => $parsed['body_html'] ?? '',
            'raw_mime' => $parsed['raw'] ?? '',
            'message_id' => $messageId,
            'in_reply_to' => $parsed['in_reply_to'] ?? null,
            'imap_uid' => $message['uid'],
            'imap_uidvalidity' => $uidvalidity,
            'is_matched' => $ticket !== null ? 'TRUE' : 'FALSE',
            'is_processed' => $ticket !== null ? 'TRUE' : 'FALSE',
            'error_message' => $ticket === null ? 'Could not determine a target from the message.' : null,
            'received_at' => $parsed['date'] ?? $message['internal_date'] ?? date('Y-m-d H:i:s'),
        ]);

        return $ticket === null ? 'skipped' : 'created';
    }

    /**
     * @param array{uid: int, raw: string, internal_date: ?string} $message
     */
    private function processResponse(array $message, string $mailbox, int $uidvalidity): string
    {
        $parsed = EmailParser::parse($message['raw'], $message['internal_date']);

        $messageId = (string) ($parsed['message_id'] ?? '');
        if ($messageId !== '' && InboundMessage::findByMessageId($messageId) !== null) {
            return 'skipped';
        }

        $ticket = $this->matchTicket($parsed);
        $isReply = ($parsed['in_reply_to'] ?? '') !== ''
            || ($parsed['references'] ?? []) !== []
            || stripos((string) ($parsed['subject'] ?? ''), 're:') === 0;

        if ($ticket === null && !$isReply) {
            // Not a reply and not matching a ticket: leave it for the intake pass.
            InboundMessage::store([
                'mailbox' => 'responses',
                'from_address' => $parsed['from'] ?? null,
                'to_address' => $parsed['to'] ?? null,
                'subject' => $parsed['subject'] ?? '',
                'body_text' => $parsed['body_text'] ?? '',
                'body_html' => $parsed['body_html'] ?? '',
                'raw_mime' => $parsed['raw'] ?? '',
                'message_id' => $messageId,
                'in_reply_to' => $parsed['in_reply_to'] ?? null,
                'imap_uid' => $message['uid'],
                'imap_uidvalidity' => $uidvalidity,
                'is_matched' => 'FALSE',
                'is_processed' => 'FALSE',
                'received_at' => $parsed['date'] ?? $message['internal_date'] ?? date('Y-m-d H:i:s'),
            ]);

            return 'skipped';
        }

        $stored = InboundMessage::store([
            'ticket_id' => $ticket?->id(),
            'mailbox' => 'responses',
            'from_address' => $parsed['from'] ?? null,
            'to_address' => $parsed['to'] ?? null,
            'subject' => $parsed['subject'] ?? '',
            'body_text' => $parsed['body_text'] ?? '',
            'body_html' => $parsed['body_html'] ?? '',
            'raw_mime' => $parsed['raw'] ?? '',
            'message_id' => $messageId,
            'in_reply_to' => $parsed['in_reply_to'] ?? null,
            'imap_uid' => $message['uid'],
            'imap_uidvalidity' => $uidvalidity,
            'is_matched' => $ticket !== null ? 'TRUE' : 'FALSE',
            'is_processed' => 'TRUE',
            'received_at' => $parsed['date'] ?? $message['internal_date'] ?? date('Y-m-d H:i:s'),
        ]);

        if ($ticket !== null) {
            TicketEvent::log(
                (int) $ticket->id(),
                'response_received',
                sprintf('Reply received from %s', (string) ($parsed['from'] ?? 'unknown')),
                'system',
                ['subject' => (string) ($parsed['subject'] ?? ''), 'inbound_id' => $stored?->id()]
            );

            return 'matched';
        }

        return 'unmatched';
    }

    /**
     * Match an inbound message to a ticket.
     *
     * @param array<string, mixed> $parsed
     */
    public function matchTicket(array $parsed): ?Ticket
    {
        // 1. Threading headers against the Message-ID we generated.
        $candidates = array_filter(array_merge(
            [(string) ($parsed['in_reply_to'] ?? '')],
            array_map('strval', (array) ($parsed['references'] ?? []))
        ));

        foreach ($candidates as $reference) {
            $reference = trim($reference, " \t<>");
            if ($reference === '') {
                continue;
            }
            foreach (OutboundReport::sentForReference($reference) as $report) {
                $ticket = Ticket::find($report->get('ticket_id'));
                if ($ticket !== null) {
                    return $ticket;
                }
            }
            // Also match a Message-ID that belongs to an intake message.
            $inbound = InboundMessage::findByMessageId($reference);
            if ($inbound !== null && $inbound->get('ticket_id') !== null) {
                $ticket = Ticket::find($inbound->get('ticket_id'));
                if ($ticket !== null) {
                    return $ticket;
                }
            }
        }

        // 2. Ticket number in the subject line.
        if (preg_match('/ABR-\d{8}-\d{4,}/i', (string) ($parsed['subject'] ?? ''), $m)) {
            $ticket = Ticket::findByNumber(strtoupper($m[0]));
            if ($ticket !== null) {
                return $ticket;
            }
        }

        // 3. Same provider domain as a contact we reported to.
        $from = strtolower((string) ($parsed['from'] ?? ''));
        if ($from !== '' && str_contains($from, '@')) {
            $domain = substr($from, strpos($from, '@') + 1);
            $row = \App\Core\Database::instance()->fetch(
                "SELECT t.* FROM tickets t
                 JOIN abuse_contacts c ON c.ticket_id = t.id
                 WHERE t.status = 'reported' AND lower(c.abuse_email) LIKE :pattern
                 ORDER BY t.reported_at DESC NULLS LAST LIMIT 1",
                ['pattern' => '%@' . $domain]
            );
            if ($row !== null) {
                return Ticket::fromRow($row);
            }
        }

        return null;
    }
}
