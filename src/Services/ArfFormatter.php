<?php

declare(strict_types=1);

namespace App\Services;

/**
 * RFC 5965 Abuse Reporting Format (ARF) message builder.
 *
 * Produces a multipart/report; report-type=feedback-report message:
 *   1. text/plain                  — human readable explanation
 *   2. message/feedback-report     — machine readable fields
 *   3. message/rfc822 (or text/plain) — evidence of the reported abuse
 */
final class ArfFormatter
{
    public const USER_AGENT = 'AbuseReportDesk/1.0';

    /**
     * RFC 5965 Feedback-Type mapping.
     */
    private const FEEDBACK_TYPES = [
        'phishing' => 'phishing',
        'malware' => 'virus',
        'spam' => 'spam',
        'fraud' => 'fraud',
        'botnet' => 'abuse',
        'other' => 'other',
    ];

    public function feedbackType(string $abuseType): string
    {
        return self::FEEDBACK_TYPES[$abuseType] ?? 'other';
    }

    public function format(ReportData $data, ?string $humanOverride = null): string
    {
        $boundary = MimeBuilder::boundary('=_arf');

        $human = $humanOverride !== null && trim($humanOverride) !== ''
            ? MimeBuilder::textPart($humanOverride)
            : $this->buildHumanPart($data);
        $feedback = $this->buildFeedbackReport($data);
        $evidence = $this->buildEvidencePart($data);

        $body = MimeBuilder::multipart('report', [$human, $feedback, $evidence], $boundary, [
            'report-type' => 'feedback-report',
        ]);

        return MimeBuilder::message([
            'From' => MimeBuilder::address($data->reporterFromEmail, $data->reporterOrg),
            'To' => $data->recipient,
            'Subject' => MimeBuilder::encodeHeader($data->subject),
            'Date' => $data->dateHeader,
            'Message-ID' => $data->messageId,
            'Auto-Submitted' => 'auto-generated',
            'X-Auto-Response-Suppress' => 'OOF, AutoReply',
            'X-Abuse-Ticket' => $data->ticketNumber,
            'X-Mailer' => self::USER_AGENT,
        ], $body);
    }

    /**
     * Human readable part (1/3).
     */
    public function buildHumanPart(ReportData $data): string
    {
        return MimeBuilder::textPart($this->plainHumanText($data));
    }

    /**
     * The plain-text body of the human readable part (also used as the
     * editable draft in the admin preview screen).
     */
    public function plainHumanText(ReportData $data): string
    {
        $evidenceList = [];
        foreach ($data->evidence as $index => $item) {
            $evidenceList[] = sprintf('  %d. %s (%s)', $index + 1, $item['filename'], $item['content_type']);
        }

        $vars = [
            'ticket_number' => $data->ticketNumber,
            'abuse_type' => $data->abuseType,
            'abuse_type_label' => $data->abuseTypeLabel,
            'feedback_type' => $this->feedbackType($data->abuseType),
            'target' => $data->target(),
            'url' => (string) ($data->reportedUrl ?? 'n/a'),
            'domain' => (string) ($data->reportedDomain ?? 'n/a'),
            'ip' => (string) ($data->reportedIp ?? 'n/a'),
            'description' => $data->description !== '' ? $data->description : 'No additional description was provided.',
            'reporter_email' => $data->reporterEmail,
            'reporter_name' => $data->reporterName !== '' ? $data->reporterName : 'not provided',
            'org' => $data->reporterOrg,
            'org_email' => $data->reporterFromEmail,
            'org_domain' => $data->reporterOrgDomain,
            'ticket_url' => $data->ticketUrl,
            'evidence_list' => $evidenceList === [] ? '  (none attached)' : implode("\n", $evidenceList),
            'enrichment' => $this->enrichmentBlock($data),
            'date' => $data->dateHeader,
        ];

        $template = Templates::exists('arf_' . $data->abuseType) ? 'arf_' . $data->abuseType : 'arf_generic';
        $text = Templates::render($template, $vars, 'arf_generic');
        if (trim($text) === '') {
            $text = $this->fallbackHumanText($data, $evidenceList);
        }

        return $text;
    }

    /**
     * Machine readable part (2/3).
     */
    public function buildFeedbackReport(ReportData $data): string
    {
        $fields = [
            'Feedback-Type: ' . $this->feedbackType($data->abuseType),
            'User-Agent: ' . self::USER_AGENT,
            'Version: 1',
            'Original-Mail-From: ' . $data->reporterEmail,
            'Original-Rcpt-To: ' . $data->recipient,
            'Arrival-Date: ' . $data->dateHeader,
            'Abuse-Type: ' . $data->abuseType,
            'Reported-Domain: ' . (string) ($data->reportedDomain ?? ''),
            'Reported-URI: ' . (string) ($data->reportedUrl ?? ''),
            'Source-IP: ' . (string) ($data->reportedIp ?? ''),
            'X-Ticket-Number: ' . $data->ticketNumber,
        ];

        $fields = array_values(array_filter($fields, static fn (string $line): bool => !str_ends_with($line, ': ')));

        return MimeBuilder::part([
            'Content-Type' => 'message/feedback-report',
            'Content-Transfer-Encoding' => '7bit',
        ], implode(MimeBuilder::CRLF, $fields) . MimeBuilder::CRLF);
    }

    /**
     * Evidence part (3/3): the original message when available, otherwise the
     * reporter description plus SHA-256 digests of the attached files.
     */
    public function buildEvidencePart(ReportData $data): string
    {
        if ($data->originalMessage !== null && trim($data->originalMessage) !== '') {
            $headersOnly = $this->headersOnly($data->originalMessage);

            return MimeBuilder::part([
                'Content-Type' => 'text/rfc822-headers; charset=utf-8',
                'Content-Transfer-Encoding' => 'quoted-printable',
            ], MimeBuilder::quotedPrintable(
                "# Original message submitted by the reporter\n" . $headersOnly
            ));
        }

        $lines = [
            '# Evidence summary',
            'Ticket: ' . $data->ticketNumber,
            'Reported target: ' . $data->target(),
        ];
        if ($data->reportedUrl !== null) {
            $lines[] = 'Reported URL: ' . $data->reportedUrl;
        }
        $lines[] = '';
        $lines[] = '## Reporter description';
        $lines[] = $data->description !== '' ? $data->description : '(none provided)';
        $lines[] = '';

        if ($data->evidence !== []) {
            $lines[] = '## Attached evidence (SHA-256)';
            foreach ($data->evidence as $item) {
                $lines[] = sprintf('%-50s %s', $item['filename'], hash('sha256', $item['data']));
            }
            $lines[] = '';
        }

        $enrichment = $this->enrichmentBlock($data);
        if (trim($enrichment) !== '') {
            $lines[] = '## Technical details';
            $lines[] = $enrichment;
        }

        return MimeBuilder::textPart(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function enrichmentBlock(ReportData $data): string
    {
        $lines = $data->enrichmentHighlights();

        return $lines === [] ? 'No enrichment data available.' : implode("\n", $lines);
    }

    private function headersOnly(string $raw): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $position = strpos($normalized, "\n\n");
        $headers = $position === false ? $normalized : substr($normalized, 0, $position);

        return str_limit($headers, 8000, "\n[truncated]\n");
    }

    /**
     * @param list<string> $evidenceList
     */
    private function fallbackHumanText(ReportData $data, array $evidenceList): string
    {
        return implode("\n", [
            'Abuse report — ' . $data->ticketNumber,
            '',
            'Type: ' . $data->abuseTypeLabel,
            'Target: ' . $data->target(),
            'URL: ' . (string) ($data->reportedUrl ?? 'n/a'),
            'IP: ' . (string) ($data->reportedIp ?? 'n/a'),
            '',
            'Description:',
            $data->description !== '' ? $data->description : '(none provided)',
            '',
            'Evidence:',
            $evidenceList === [] ? '  (none attached)' : implode("\n", $evidenceList),
            '',
            'Reported by ' . $data->reporterOrg . ' (' . $data->reporterFromEmail . ')',
            'Ticket reference: ' . $data->ticketUrl,
        ]);
    }
}
