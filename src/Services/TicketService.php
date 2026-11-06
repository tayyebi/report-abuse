<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\AbuseContact;
use App\Models\Evidence;
use App\Models\OutboundReport;
use App\Models\Ticket;
use App\Models\TicketEvent;
use RuntimeException;

/**
 * Business logic for the ticket lifecycle.
 */
final class TicketService
{
    public function __construct(
        private readonly EvidenceService $evidence = new EvidenceService(),
        private readonly EnrichmentService $enricher = new EnrichmentService(),
        private readonly NotificationService $notifier = new NotificationService(),
        private readonly ReportComposer $composer = new ReportComposer(),
        private readonly SmtpMailer $mailer = new SmtpMailer()
    ) {
    }

    /*
     * ---------------------------------------------------------------------
     * Intake
     * ---------------------------------------------------------------------
     */

    /**
     * Normalise user supplied target information.
     *
     * @param array<string, mixed> $input
     * @return array{url: ?string, domain: ?string, ip: ?string, error: ?string}
     */
    public static function normaliseTarget(array $input): array
    {
        $rawUrl = trim((string) ($input['reported_url'] ?? ''));
        $rawDomain = trim((string) ($input['reported_domain'] ?? ''));
        $rawIp = trim((string) ($input['reported_ip'] ?? ''));

        // A single "target" field is also accepted from the public form.
        $target = trim((string) ($input['target'] ?? ''));
        if ($target !== '' && $rawUrl === '' && $rawDomain === '' && $rawIp === '') {
            if (filter_var($target, FILTER_VALIDATE_IP)) {
                $rawIp = $target;
            } elseif (preg_match('#^https?://#i', $target) || str_contains($target, '/')) {
                $rawUrl = $target;
            } else {
                $rawDomain = $target;
            }
        }

        $url = null;
        $domain = null;
        $ip = null;

        if ($rawIp !== '') {
            if (!filter_var($rawIp, FILTER_VALIDATE_IP)) {
                return ['url' => null, 'domain' => null, 'ip' => null, 'error' => 'The IP address is not valid.'];
            }
            $ip = $rawIp;
        }

        if ($rawUrl !== '') {
            if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $rawUrl)) {
                $rawUrl = 'http://' . ltrim($rawUrl, '/');
            }
            $parts = parse_url($rawUrl);
            if (!is_array($parts) || empty($parts['host'])) {
                return ['url' => null, 'domain' => null, 'ip' => null, 'error' => 'The URL could not be parsed.'];
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
            if (!in_array($scheme, ['http', 'https'], true)) {
                return ['url' => null, 'domain' => null, 'ip' => null, 'error' => 'Only http and https URLs can be reported.'];
            }
            $host = strtolower(trim((string) $parts['host'], '[]'));

            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ip = $ip ?? $host;
            } else {
                if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $host)) {
                    return ['url' => null, 'domain' => null, 'ip' => null, 'error' => 'The host name in the URL is not valid.'];
                }
                $domain = $domain ?? $host;
            }

            $url = $rawUrl;
        }

        if ($rawDomain !== '') {
            $candidate = strtolower(trim($rawDomain));
            $candidate = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $candidate) ?? $candidate;
            $candidate = explode('/', $candidate)[0];
            $candidate = trim($candidate, '[]');

            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $ip ?? $candidate;
            } elseif (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $candidate)) {
                $domain = $domain ?? $candidate;
            } else {
                return ['url' => null, 'domain' => null, 'ip' => null, 'error' => 'The domain name is not valid.'];
            }
        }

        if ($url === null && $domain !== null) {
            $url = 'http://' . $domain;
        }

        if ($url === null && $ip === null) {
            return ['url' => null, 'domain' => null, 'ip' => null, 'error' => 'Please provide a URL, domain name or IP address.'];
        }

        return ['url' => $url, 'domain' => $domain, 'ip' => $ip, 'error' => null];
    }

    public static function isValidAbuseType(string $type): bool
    {
        return array_key_exists($type, (array) Config::get('app.abuse_types', []));
    }

    /**
     * Handle a public web submission.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $files
     * @return array{ticket: ?Ticket, errors: array<string, string>}
     */
    public function submitWeb(array $input, ?array $files = null, string $ip = '0.0.0.0'): array
    {
        $errors = [];

        $email = trim((string) ($input['reporter_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['reporter_email'] = 'Please enter a valid e-mail address so we can keep you updated.';
        }

        $abuseType = (string) ($input['abuse_type'] ?? 'other');
        if (!self::isValidAbuseType($abuseType)) {
            $errors['abuse_type'] = 'Please choose the kind of abuse you are reporting.';
        }

        $target = self::normaliseTarget($input);
        if ($target['error'] !== null) {
            $errors['target'] = $target['error'];
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (strlen($description) < 20) {
            $errors['description'] = 'Please describe the abuse in at least 20 characters.';
        }
        if (strlen($description) > 20000) {
            $errors['description'] = 'The description is too long (20,000 characters maximum).';
        }

        $maxFiles = (int) Config::get('app.intake.max_evidence_files', 5);
        $fileList = [];
        if (is_array($files)) {
            foreach ($files as $key => $file) {
                if (is_array($file) && isset($file['name']) && is_array($file['name'])) {
                    foreach ($file['name'] as $index => $name) {
                        if (($file['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                            $fileList[] = [
                                'name' => (string) $name,
                                'type' => (string) ($file['type'][$index] ?? ''),
                                'tmp_name' => (string) ($file['tmp_name'][$index] ?? ''),
                                'error' => (int) $file['error'][$index],
                                'size' => (int) ($file['size'][$index] ?? 0),
                            ];
                        }
                    }
                } elseif (is_array($file) && isset($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $fileList[] = [
                        'name' => (string) $file['name'],
                        'type' => (string) ($file['type'] ?? ''),
                        'tmp_name' => (string) $file['tmp_name'],
                        'error' => (int) $file['error'],
                        'size' => (int) ($file['size'] ?? 0),
                    ];
                }
            }
        }

        if (count($fileList) > $maxFiles) {
            $errors['files'] = sprintf('You can attach at most %d files.', $maxFiles);
        }
        foreach ($fileList as $file) {
            $error = $this->evidence->validate($file);
            if ($error !== null) {
                $errors['files'] = $error;
                break;
            }
        }

        if ($errors !== []) {
            return ['ticket' => null, 'errors' => $errors];
        }

        $ticket = Ticket::createTicket([
            'status' => Ticket::STATUS_NEW,
            'abuse_type' => $abuseType,
            'reported_url' => $target['url'],
            'reported_domain' => $target['domain'],
            'reported_ip' => $target['ip'],
            'reporter_email' => $email,
            'reporter_name' => trim((string) ($input['reporter_name'] ?? '')) ?: null,
            'reporter_ip' => $ip,
            'description' => $description,
            'source' => 'web',
        ]);

        TicketEvent::log((int) $ticket->id(), 'created', sprintf('Ticket created via web form (%s)', $abuseType), 'system', [
            'target' => $ticket->target(),
            'reporter' => $email,
        ]);

        foreach ($fileList as $file) {
            try {
                $this->evidence->storeUploaded((int) $ticket->id(), $file, 'reporter');
            } catch (RuntimeException $e) {
                TicketEvent::log((int) $ticket->id(), 'evidence_failed', $e->getMessage(), 'system');
            }
        }

        $this->notifier->sendReportConfirmation($ticket);
        $this->notifier->notifyAdminsNewTicket($ticket);

        return ['ticket' => $ticket, 'errors' => []];
    }

    /**
     * Create a ticket from an inbound e-mail (intake mailbox).
     *
     * @param array<string, mixed> $parsed output of EmailParser::parse()
     */
    public function createFromEmail(array $parsed, string $mailbox = 'intake'): ?Ticket
    {
        $from = trim((string) ($parsed['from'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $subject = (string) ($parsed['subject'] ?? '');
        $body = (string) ($parsed['body_text'] ?? '');
        $haystack = $subject . "\n" . $body;

        $target = self::normaliseTarget(['target' => $this->guessTarget($haystack)]);
        if ($target['error'] !== null) {
            return null;
        }

        $abuseType = $this->guessAbuseType($haystack);

        $description = trim($body);
        if ($description === '') {
            $description = trim($subject);
        }
        if (strlen($description) < 20) {
            $description = str_pad($description, 20, ' ');
        }

        $ticket = Ticket::createTicket([
            'status' => Ticket::STATUS_NEW,
            'abuse_type' => $abuseType,
            'reported_url' => $target['url'],
            'reported_domain' => $target['domain'],
            'reported_ip' => $target['ip'],
            'reporter_email' => $from,
            'reporter_name' => (string) ($parsed['from_name'] ?? '') ?: null,
            'description' => str_limit($description, 20000, "\n[truncated]"),
            'source' => 'email',
            'source_message_id' => (string) ($parsed['message_id'] ?? '') ?: null,
        ]);

        TicketEvent::log((int) $ticket->id(), 'created', 'Ticket created from inbound e-mail', 'system', [
            'mailbox' => $mailbox,
            'subject' => $subject,
            'reporter' => $from,
        ]);

        // Keep the original message as evidence.
        $raw = (string) ($parsed['raw'] ?? '');
        if ($raw !== '') {
            try {
                $this->evidence->storeRaw((int) $ticket->id(), 'original-message.eml', $raw, 'message/rfc822', 'system', 'eml');
            } catch (RuntimeException) {
                // Non fatal.
            }
        }
        foreach ((array) ($parsed['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment) || !isset($attachment['data'])) {
                continue;
            }
            try {
                $this->evidence->storeRaw(
                    (int) $ticket->id(),
                    (string) ($attachment['filename'] ?? 'attachment.bin'),
                    (string) $attachment['data'],
                    (string) ($attachment['content_type'] ?? 'application/octet-stream'),
                    'reporter'
                );
            } catch (RuntimeException) {
                // Non fatal.
            }
        }

        $this->notifier->sendReportConfirmation($ticket);
        $this->notifier->notifyAdminsNewTicket($ticket);

        return $ticket;
    }

    /**
     * Best-effort extraction of the first URL/host mentioned in a message.
     */
    public function guessTarget(string $text): string
    {
        if (preg_match('#https?://[^\s<>"\')\]]+#i', $text, $m)) {
            return rtrim($m[0], ".,;:!?");
        }
        if (preg_match('#\b([a-z0-9][a-z0-9\-]*\.)+[a-z]{2,63}(:\d+)?(/[^\s<>"\']*)?#i', $text, $m)) {
            return $m[0];
        }
        if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $text, $m)) {
            return $m[0];
        }

        return '';
    }

    public function guessAbuseType(string $text): string
    {
        $text = strtolower($text);
        $map = [
            'phishing' => ['phish', 'credential', 'fake login', 'spoof'],
            'malware' => ['malware', 'virus', 'trojan', 'ransom', 'payload', 'exe'],
            'spam' => ['spam', 'unsolicited', 'bulk mail', 'newsletter abuse'],
            'fraud' => ['fraud', 'scam', 'fake shop', 'investment'],
            'botnet' => ['botnet', 'c2', 'command and control', 'ddos'],
        ];

        foreach ($map as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $type;
                }
            }
        }

        return 'other';
    }

    /*
     * ---------------------------------------------------------------------
     * Enrichment
     * ---------------------------------------------------------------------
     */

    /**
     * Run enrichment (when stale) and persist contacts.
     *
     * @return array<string, mixed>
     */
    public function enrich(Ticket $ticket, bool $force = false): array
    {
        $interval = (int) Config::get('app.piggyback.enrichment_interval', 900);
        $lastRun = $ticket->get('enrichment_at');
        $fresh = $lastRun !== null && (time() - (int) strtotime((string) $lastRun)) < $interval;

        if (!$force && $ticket->isEnriched() && $fresh) {
            return $ticket->enrichment();
        }

        $started = microtime(true);
        $data = $this->enricher->enrich($ticket, $force);
        $data['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        $ticket->markEnriched($data);

        $discovered = AbuseContactFinder::discover($data, $ticket->domain(), $ticket->ip());
        $added = 0;
        foreach ($discovered as $contact) {
            $existing = AbuseContact::add(
                (int) $ticket->id(),
                $contact['email'],
                $contact['source'],
                $contact['provider'] !== '' ? $contact['provider'] : null,
                ['source' => $contact['source']]
            );
            if ($existing !== null) {
                $added++;
            }
        }

        TicketEvent::log(
            (int) $ticket->id(),
            'enriched',
            sprintf('Enrichment completed: %d candidate contact(s)', $added),
            'system',
            ['duration_ms' => $data['duration_ms'], 'errors' => $data['errors'] ?? []]
        );

        return $data;
    }

    /*
     * ---------------------------------------------------------------------
     * Workflow
     * ---------------------------------------------------------------------
     */

    public function approve(Ticket $ticket, int $contactId): bool
    {
        if (!$ticket->isOpen()) {
            return false;
        }
        if (!AbuseContact::select((int) $ticket->id(), $contactId)) {
            return false;
        }

        $contact = AbuseContact::find($contactId);
        $ticket->update([
            'status' => Ticket::STATUS_APPROVED,
            'approved_at' => $ticket->get('approved_at') ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        TicketEvent::log(
            (int) $ticket->id(),
            'approved',
            'Report approved for ' . ($contact?->email() ?? 'contact'),
            'admin'
        );

        return true;
    }

    /**
     * @return array{ok: bool, error: ?string, report: ?OutboundReport}
     */
    public function sendReport(
        Ticket $ticket,
        string $recipient,
        string $format = 'arf',
        ?string $subject = null,
        ?string $humanText = null
    ): array {
        $recipient = strtolower(trim($recipient));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'The recipient address is not valid.', 'report' => null];
        }

        $composed = $this->composer->compose($ticket, $recipient, $format, $subject, $humanText);
        $result = $this->mailer->send($recipient, $composed['mime']);

        $contact = AbuseContact::selectedFor((int) $ticket->id());
        $report = OutboundReport::record((int) $ticket->id(), [
            'abuse_contact_id' => $contact?->id(),
            'format' => $composed['format'],
            'recipient_email' => $recipient,
            'subject' => $composed['subject'],
            'body' => $composed['human_text'],
            'raw_mime' => $composed['mime'],
            'smtp_message_id' => $composed['message_id'],
            'send_status' => $result['ok'] ? 'sent' : 'failed',
            'error_message' => $result['error'],
            'sent_at' => $result['ok'] ? date('Y-m-d H:i:s') : null,
        ]);

        if (!$result['ok']) {
            TicketEvent::log((int) $ticket->id(), 'send_failed', 'Delivery to ' . $recipient . ' failed: ' . (string) $result['error'], 'system');
            $ticket->update(['updated_at' => date('Y-m-d H:i:s')]);

            return ['ok' => false, 'error' => $result['error'], 'report' => $report];
        }

        $ticket->update([
            'status' => Ticket::STATUS_REPORTED,
            'reported_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        TicketEvent::log(
            (int) $ticket->id(),
            'reported',
            sprintf('%s report sent to %s', strtoupper($composed['format']), $recipient),
            'admin',
            ['message_id' => $composed['message_id'], 'transport' => $result['transport']]
        );

        $this->notifier->sendReportForwarded($ticket, $recipient);

        return ['ok' => true, 'error' => null, 'report' => $report];
    }

    public function resolve(Ticket $ticket, string $note = ''): void
    {
        $ticket->update([
            'status' => Ticket::STATUS_RESOLVED,
            'resolved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        TicketEvent::log((int) $ticket->id(), 'resolved', $note !== '' ? $note : 'Ticket resolved', 'admin');
        $this->notifier->sendResolved($ticket, $note);
    }

    public function reject(Ticket $ticket, string $reason = ''): void
    {
        $ticket->update([
            'status' => Ticket::STATUS_REJECTED,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        TicketEvent::log((int) $ticket->id(), 'rejected', $reason !== '' ? $reason : 'Ticket rejected by analyst', 'admin');
    }

    public function close(Ticket $ticket, string $note = ''): void
    {
        $ticket->update([
            'status' => Ticket::STATUS_CLOSED,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        TicketEvent::log((int) $ticket->id(), 'closed', $note !== '' ? $note : 'Ticket closed', 'admin');
    }

    public function reopen(Ticket $ticket): void
    {
        $status = $ticket->get('reported_at') !== null ? Ticket::STATUS_REPORTED : Ticket::STATUS_NEW;
        $ticket->update([
            'status' => $status,
            'resolved_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        TicketEvent::log((int) $ticket->id(), 'reopened', 'Ticket reopened', 'admin');
    }

    public function saveNotes(Ticket $ticket, string $notes): void
    {
        $ticket->update([
            'admin_notes' => $notes,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        TicketEvent::log((int) $ticket->id(), 'notes_updated', 'Internal notes updated', 'admin');
    }

    public function addContact(Ticket $ticket, string $email, string $provider = ''): ?AbuseContact
    {
        $contact = AbuseContact::add((int) $ticket->id(), $email, 'manual', $provider !== '' ? $provider : null);
        if ($contact !== null) {
            TicketEvent::log((int) $ticket->id(), 'contact_added', 'Abuse contact added: ' . strtolower(trim($email)), 'admin');
        }

        return $contact;
    }

    public function uploadEvidence(Ticket $ticket, array $file, string $actor = 'admin'): Evidence
    {
        $evidence = $this->evidence->storeUploaded((int) $ticket->id(), $file, $actor);
        TicketEvent::log((int) $ticket->id(), 'evidence_added', 'Evidence uploaded: ' . $evidence->originalName(), $actor);

        return $evidence;
    }

    /**
     * Number of submissions from an IP inside the sliding window.
     */
    public function recentSubmissionCount(string $ip, int $windowSeconds = 3600): int
    {
        if ($ip === '' || $ip === '0.0.0.0') {
            return 0;
        }

        return (int) \App\Core\Database::instance()->fetchValue(
            "SELECT COUNT(*) FROM tickets WHERE reporter_ip = :ip AND created_at > NOW() - (:secs || ' seconds')::interval",
            ['ip' => $ip, 'secs' => (string) max(1, $windowSeconds)]
        );
    }
}
