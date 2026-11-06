<?php

declare(strict_types=1);

namespace App\Services;

/**
 * X-ARF (JSON) report builder.
 *
 * The report body is a multipart/report message whose second part is an
 * application/json X-ARF document, so both humans and parsers can consume it.
 */
final class XarfFormatter
{
    private const REPORT_TYPES = [
        'phishing' => 'Phishing',
        'malware' => 'Malware',
        'spam' => 'Spam',
        'fraud' => 'Other',
        'botnet' => 'Malware',
        'other' => 'Other',
    ];

    public function reportType(string $abuseType): string
    {
        return self::REPORT_TYPES[$abuseType] ?? 'Other';
    }

    public function format(ReportData $data, ?string $humanOverride = null): string
    {
        $boundary = MimeBuilder::boundary('=_xarf');

        $json = json_encode($this->buildReport($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $summary = MimeBuilder::textPart(
            $humanOverride !== null && trim($humanOverride) !== '' ? $humanOverride : $this->buildHumanText($data)
        );
        $jsonPart = MimeBuilder::part([
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
        ], MimeBuilder::quotedPrintable((string) $json));

        $body = MimeBuilder::multipart('report', [$summary, $jsonPart], $boundary, [
            'report-type' => 'x-arf',
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
            'X-XARF' => 'report',
            'X-Mailer' => ArfFormatter::USER_AGENT,
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReport(ReportData $data): array
    {
        $report = [
            'TicketId' => $data->ticketNumber,
            'Date' => date('c'),
            'Domain' => (string) ($data->reportedDomain ?? ''),
            'Uri' => (string) ($data->reportedUrl ?? ''),
            'SourceIp' => (string) ($data->reportedIp ?? ''),
            'Subject' => $data->subject,
            'Language' => 'en',
            'PublicRef' => $data->ticketUrl,
            'AttackDescription' => $this->buildHumanText($data),
        ];

        foreach (['Domain' => 'reportedDomain', 'Uri' => 'reportedUrl', 'SourceIp' => 'reportedIp'] as $key => $property) {
            if (empty($report[$key])) {
                unset($report[$key]);
            }
        }

        $attachments = [];
        foreach ($data->evidence as $item) {
            $attachments[] = [
                'Description' => 'Evidence submitted with the abuse report',
                'FileName' => $item['filename'],
                'ContentType' => $item['content_type'],
                'Data' => base64_encode($item['data']),
                'Hash' => hash('sha256', $item['data']),
            ];
        }

        $xarf = [
            'Version' => '3',
            'ReporterInfo' => [
                'ReporterOrg' => $data->reporterOrg,
                'ReporterOrgDomain' => $data->reporterOrgDomain,
                'ReporterOrgEmail' => $data->reporterFromEmail,
                'ReporterContactName' => $data->reporterName !== '' ? $data->reporterName : $data->reporterOrg,
                'ReporterContactEmail' => $data->reporterEmail,
            ],
            'ReportType' => $this->reportType($data->abuseType),
            'Report' => $report,
        ];

        if ($attachments !== []) {
            $xarf['Attachment'] = $attachments;
        }

        return $xarf;
    }

    public function buildHumanText(ReportData $data): string
    {
        $lines = [
            'X-ARF abuse report — ' . $data->ticketNumber,
            '',
            'Report type: ' . $this->reportType($data->abuseType) . ' (' . $data->abuseTypeLabel . ')',
            'Target: ' . $data->target(),
        ];
        if ($data->reportedUrl !== null) {
            $lines[] = 'URL: ' . $data->reportedUrl;
        }
        if ($data->reportedIp !== null) {
            $lines[] = 'IP address: ' . $data->reportedIp;
        }
        $lines[] = '';
        $lines[] = 'Description:';
        $lines[] = $data->description !== '' ? $data->description : '(none provided)';
        $lines[] = '';
        $lines[] = 'Reported by ' . $data->reporterOrg . ' <' . $data->reporterFromEmail . '>';
        $lines[] = 'Ticket: ' . $data->ticketUrl;
        $lines[] = '';
        $lines[] = 'The machine-readable X-ARF JSON document is attached as application/json.';

        $highlights = $data->enrichmentHighlights();
        if ($highlights !== []) {
            $lines[] = '';
            $lines[] = 'Technical details:';
            foreach ($highlights as $line) {
                $lines[] = '  - ' . $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A standalone JSON document (used by the admin preview / download).
     */
    public function formatStandalone(ReportData $data): string
    {
        return (string) json_encode($this->buildReport($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
