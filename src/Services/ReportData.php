<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\AbuseContact;
use App\Models\Evidence;
use App\Models\Ticket;

/**
 * Everything a formatter needs, independent of the database.
 */
final class ReportData
{
    /**
     * @param list<array{filename: string, content_type: string, data: string}> $evidence
     * @param array<string, mixed> $enrichment
     */
    public function __construct(
        public readonly string $ticketNumber,
        public readonly string $abuseType,
        public readonly string $abuseTypeLabel,
        public readonly ?string $reportedUrl,
        public readonly ?string $reportedDomain,
        public readonly ?string $reportedIp,
        public readonly string $description,
        public readonly string $reporterEmail,
        public readonly string $reporterName,
        public readonly string $reporterOrg,
        public readonly string $reporterOrgDomain,
        public readonly string $reporterFromEmail,
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $messageId,
        public readonly string $dateHeader,
        public readonly string $ticketUrl,
        public readonly array $evidence = [],
        public readonly ?string $originalMessage = null,
        public readonly array $enrichment = [],
        public readonly string $providerName = ''
    ) {
    }

    /**
     * @param list<Evidence> $evidence
     */
    public static function fromTicket(Ticket $ticket, string $recipient, ?string $subject = null): self
    {
        $org = (string) Config::get('app.reporter.org', 'Abuse Report Desk');
        $orgDomain = (string) Config::get('app.reporter.domain', 'abuse-desk.local');
        $fromEmail = (string) Config::get('app.reporter.email', 'abuse@' . $orgDomain);

        $contact = AbuseContact::selectedFor((int) $ticket->id());
        $evidence = [];
        foreach ($ticket->evidence() as $item) {
            if (!$item->exists()) {
                continue;
            }
            $data = (string) file_get_contents($item->absolutePath());
            if (strlen($data) > 262_144) {
                continue; // keep outbound reports small
            }
            $evidence[] = [
                'filename' => $item->originalName(),
                'content_type' => (string) ($item->get('mime_type') ?: 'application/octet-stream'),
                'data' => $data,
            ];
        }

        $originalMessage = null;
        if ($ticket->get('source') === 'email' && $ticket->get('source_message_id')) {
            $inbound = \App\Models\InboundMessage::findByMessageId((string) $ticket->get('source_message_id'));
            if ($inbound !== null) {
                $originalMessage = (string) $inbound->get('raw_mime');
            }
        }

        $targetLabel = $ticket->domain() ?? $ticket->ip() ?? $ticket->url() ?? 'reported target';
        $subject = $subject ?? sprintf('[%s] %s report for %s', $ticket->number(), $ticket->abuseTypeLabel(), $targetLabel);

        return new self(
            ticketNumber: $ticket->number(),
            abuseType: $ticket->abuseType(),
            abuseTypeLabel: $ticket->abuseTypeLabel(),
            reportedUrl: $ticket->url(),
            reportedDomain: $ticket->domain(),
            reportedIp: $ticket->ip(),
            description: trim((string) $ticket->get('description', '')),
            reporterEmail: (string) $ticket->get('reporter_email', ''),
            reporterName: (string) $ticket->get('reporter_name', ''),
            reporterOrg: $org,
            reporterOrgDomain: $orgDomain,
            reporterFromEmail: $fromEmail,
            recipient: strtolower(trim($recipient)),
            subject: $subject,
            messageId: MimeBuilder::messageId($orgDomain, 'report'),
            dateHeader: MimeBuilder::dateHeader(),
            ticketUrl: rtrim((string) Config::get('app.url', ''), '/') . '/ticket/' . $ticket->token(),
            evidence: $evidence,
            originalMessage: $originalMessage,
            enrichment: $ticket->enrichment(),
            providerName: $contact?->provider() ?? ''
        );
    }

    public function target(): string
    {
        return $this->reportedDomain ?? $this->reportedIp ?? $this->reportedUrl ?? 'unknown';
    }

    /**
     * Short list of enrichment highlights for the human-readable part.
     *
     * @return list<string>
     */
    public function enrichmentHighlights(): array
    {
        $lines = [];

        $dns = (array) ($this->enrichment['dns'] ?? []);
        foreach (['a' => 'A', 'aaaa' => 'AAAA', 'mx' => 'MX', 'ns' => 'NS'] as $key => $label) {
            $records = (array) ($dns[$key] ?? []);
            if ($records !== []) {
                $lines[] = $label . ' records: ' . implode(', ', array_slice($records, 0, 6));
            }
        }

        $rdap = (array) ($this->enrichment['rdap_domain'] ?? []);
        if (!empty($rdap['registrar'])) {
            $lines[] = 'Registrar: ' . $rdap['registrar'];
        }
        if (!empty($rdap['status'])) {
            $lines[] = 'Domain status: ' . implode(', ', (array) $rdap['status']);
        }

        $rdapIp = (array) ($this->enrichment['rdap_ip'] ?? []);
        if (!empty($rdapIp['network'])) {
            $lines[] = 'IP network: ' . $rdapIp['network'];
        }
        if (!empty($rdapIp['country'])) {
            $lines[] = 'Network country: ' . $rdapIp['country'];
        }

        $http = (array) ($this->enrichment['http'] ?? []);
        if (!empty($http['status'])) {
            $lines[] = 'HTTP probe: ' . $http['status'] . (!empty($http['final_url']) ? ' (' . $http['final_url'] . ')' : '');
        }

        return $lines;
    }
}
