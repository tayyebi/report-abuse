<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Admin;
use App\Models\Ticket;

/**
 * Reporter- and admin-facing e-mail notifications.
 */
final class NotificationService
{
    public function __construct(private readonly SmtpMailer $mailer = new SmtpMailer())
    {
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function sendLoginCode(Admin $admin, string $code, int $ttlMinutes, string $ip): array
    {
        $body = Templates::render('admin_login_code', [
            'app_name' => (string) Config::get('app.name', 'Abuse Report Desk'),
            'app_url' => (string) Config::get('app.url', ''),
            'code' => $code,
            'ttl_minutes' => (string) $ttlMinutes,
            'date' => date('Y-m-d H:i:s T'),
            'ip' => $ip,
        ], 'admin_login_code');

        $subject = sprintf('%s sign-in code', (string) Config::get('app.name', 'Abuse Report Desk'));

        return $this->dispatch($admin->email(), $subject, $body);
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function sendReportConfirmation(Ticket $ticket): array
    {
        return $this->sendToReporter($ticket, 'reporter_confirmation', [
            'date' => (string) ($ticket->get('created_at') ?? date('Y-m-d H:i:s')),
        ]);
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function sendReportForwarded(Ticket $ticket, string $recipient): array
    {
        return $this->sendToReporter($ticket, 'reporter_update', [
            'recipient' => $recipient,
        ]);
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function sendResolved(Ticket $ticket, string $note = ''): array
    {
        return $this->sendToReporter($ticket, 'reporter_resolved', [
            'resolution' => $note !== '' ? $note : 'The provider has been notified and the case is now closed.',
        ]);
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function notifyAdminsNewTicket(Ticket $ticket): array
    {
        $recipients = array_filter(array_map(
            static fn (Admin $admin): string => $admin->email(),
            Admin::where('is_active', true, 'id ASC')
        ));

        $subject = sprintf('[%s] New %s report — %s', $ticket->number(), $ticket->abuseTypeLabel(), $ticket->target());
        $body = Templates::render('admin_new_ticket', [
            'ticket_number' => $ticket->number(),
            'abuse_type_label' => $ticket->abuseTypeLabel(),
            'target' => $ticket->target(),
            'source' => (string) $ticket->get('source', 'web'),
            'reporter_email' => (string) $ticket->get('reporter_email', ''),
            'description' => (string) $ticket->get('description', '(none provided)'),
            'date' => (string) ($ticket->get('created_at') ?? date('Y-m-d H:i:s')),
            'admin_url' => rtrim((string) Config::get('app.url', ''), '/') . '/admin/ticket/' . $ticket->id(),
        ], 'admin_new_ticket');

        $errors = [];
        $sent = false;
        foreach ($recipients as $recipient) {
            $result = $this->dispatch($recipient, $subject, $body);
            $sent = $sent || $result['ok'];
            if (!$result['ok'] && $result['error'] !== null) {
                $errors[] = $recipient . ': ' . $result['error'];
            }
        }

        return ['ok' => $sent || $recipients === [], 'error' => $errors === [] ? null : implode('; ', $errors)];
    }

    /**
     * @param array<string, scalar|null> $extra
     * @return array{ok: bool, error: ?string}
     */
    private function sendToReporter(Ticket $ticket, string $template, array $extra = []): array
    {
        $to = (string) $ticket->get('reporter_email', '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Reporter address is not valid.'];
        }

        $name = trim((string) $ticket->get('reporter_name', ''));
        $subjectTemplate = $template === 'reporter_confirmation'
            ? sprintf('[%s] We received your abuse report', $ticket->number())
            : ($template === 'reporter_resolved'
                ? sprintf('[%s] Your abuse report has been resolved', $ticket->number())
                : sprintf('[%s] Your abuse report was forwarded', $ticket->number()));

        $body = Templates::render($template, array_merge([
            'ticket_number' => $ticket->number(),
            'abuse_type_label' => $ticket->abuseTypeLabel(),
            'target' => $ticket->target(),
            'url' => (string) ($ticket->url() ?? 'n/a'),
            'reporter_name' => $name !== '' ? $name : 'there',
            'reporter_email' => $to,
            'ticket_url' => $ticket->publicUrl(),
            'org' => (string) Config::get('app.reporter.org', 'Abuse Report Desk'),
            'date' => date('Y-m-d H:i:s'),
        ], $extra), $template);

        return $this->dispatch($to, $subjectTemplate, $body);
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    private function dispatch(string $to, string $fallbackSubject, string $body): array
    {
        $subject = $fallbackSubject;
        // Templates start with a "Subject:" line; use it when present.
        if (preg_match('/^\s*Subject:\s*(.+)$/mi', $body, $m)) {
            $subject = trim($m[1]);
            $body = preg_replace('/^\s*Subject:\s*.+\R?/mi', '', $body, 1) ?? $body;
        }

        try {
            $result = $this->mailer->sendText($to, $subject, trim($body));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (!$result['ok']) {
            $this->log('Failed to send "' . $subject . '" to ' . $to . ': ' . (string) $result['error']);

            return ['ok' => false, 'error' => $result['error']];
        }

        $this->log(sprintf('Sent "%s" to %s via %s', $subject, $to, $result['transport']));

        return ['ok' => true, 'error' => null];
    }

    private function log(string $message): void
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/notifications.log', sprintf("[%s] %s\n", date('c'), $message), FILE_APPEND);
    }
}
