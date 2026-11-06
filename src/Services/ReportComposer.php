<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;

/**
 * Picks a report format and produces the final MIME message.
 */
final class ReportComposer
{
    public const FORMATS = ['arf', 'xarf', 'plaintext'];

    public function __construct(
        private readonly ArfFormatter $arf = new ArfFormatter(),
        private readonly XarfFormatter $xarf = new XarfFormatter()
    ) {
    }

    /**
     * @return array{format: string, subject: string, mime: string, human_text: string, message_id: string}
     */
    public function compose(
        Ticket $ticket,
        string $recipient,
        string $format = 'arf',
        ?string $subject = null,
        ?string $humanText = null
    ): array {
        $format = in_array($format, self::FORMATS, true) ? $format : 'arf';
        $data = ReportData::fromTicket($ticket, $recipient, $subject);

        $humanText = $this->defaultHumanText($data, $format, $humanText);

        if ($format === 'xarf') {
            $mime = $this->xarf->format($data, $humanText);
        } elseif ($format === 'plaintext') {
            $mime = $this->plainTextMessage($data, $humanText);
        } else {
            $mime = $this->arf->format($data, $humanText);
        }

        return [
            'format' => $format,
            'subject' => $data->subject,
            'mime' => $mime,
            'human_text' => $humanText,
            'message_id' => $data->messageId,
        ];
    }

    /**
     * Human-readable draft shown in the admin preview before sending.
     */
    public function draftHumanText(Ticket $ticket, string $recipient, string $format = 'arf'): string
    {
        $data = ReportData::fromTicket($ticket, $recipient);

        return $this->defaultHumanText($data, $format, null);
    }

    private function defaultHumanText(ReportData $data, string $format, ?string $override): string
    {
        if ($override !== null && trim($override) !== '') {
            return trim($override);
        }

        return $format === 'xarf'
            ? $this->xarf->buildHumanText($data)
            : $this->arf->plainHumanText($data);
    }

    private function plainTextMessage(ReportData $data, string $humanText): string
    {
        return MimeBuilder::message([
            'From' => MimeBuilder::address($data->reporterFromEmail, $data->reporterOrg),
            'To' => $data->recipient,
            'Subject' => MimeBuilder::encodeHeader($data->subject),
            'Date' => $data->dateHeader,
            'Message-ID' => $data->messageId,
            'Auto-Submitted' => 'auto-generated',
            'X-Abuse-Ticket' => $data->ticketNumber,
            'X-Mailer' => ArfFormatter::USER_AGENT,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
        ], MimeBuilder::quotedPrintable($humanText));
    }
}
