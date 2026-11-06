<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\AbuseContact;
use App\Models\Admin;
use App\Models\Evidence;
use App\Models\InboundMessage;
use App\Models\OutboundReport;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\EnrichmentService;
use App\Services\MailboxProcessor;
use App\Services\PiggybackService;
use App\Services\ReportComposer;
use App\Services\SmtpMailer;
use App\Services\TicketService;

/**
 * Admin dashboard, ticket management, report preview/send and settings.
 */
final class AdminController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets = new TicketService(),
        private readonly ReportComposer $composer = new ReportComposer(),
        private readonly PiggybackService $piggyback = new PiggybackService(),
        private readonly MailboxProcessor $mailbox = new MailboxProcessor(),
        private readonly SmtpMailer $mailer = new SmtpMailer()
    ) {
    }

    public function dashboard(Request $request): Response
    {
        $piggyback = $this->piggyback->runOnAdminLoad(true);

        $counts = Ticket::countsByStatus();
        $recent = Ticket::search([], 10);
        $awaiting = Ticket::awaitingResponse(5);

        $newToday = (int) \App\Core\Database::instance()->fetchValue(
            "SELECT COUNT(*) FROM tickets WHERE created_at >= date_trunc('day', NOW())"
        );

        return $this->render('admin/dashboard', [
            'title' => 'Dashboard',
            'counts' => $counts,
            'total' => array_sum($counts),
            'recent' => $recent,
            'awaiting' => $awaiting,
            'newToday' => $newToday,
            'piggyback' => $piggyback,
            'inboxStats' => InboundMessage::stats(),
            'statuses' => (array) Config::get('app.statuses', []),
            'imapConfigured' => \App\Services\ImapClient::isConfigured(),
        ]);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => (string) $request->query('status', 'open'),
            'abuse_type' => (string) $request->query('abuse_type', 'all'),
            'source' => (string) $request->query('source', 'all'),
            'q' => (string) $request->query('q', ''),
        ];

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 25;

        $tickets = Ticket::search($filters, $perPage, ($page - 1) * $perPage);
        $total = Ticket::searchCount($filters);

        return $this->render('admin/tickets', [
            'title' => 'Tickets',
            'tickets' => $tickets,
            'filters' => $filters,
            'counts' => Ticket::countsByStatus(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'statuses' => (array) Config::get('app.statuses', []),
            'abuseTypes' => (array) Config::get('app.abuse_types', []),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        $enrichment = $ticket->enrichment();
        $enrichError = null;
        if (!$ticket->isEnriched()) {
            try {
                $enrichment = $this->tickets->enrich($ticket);
            } catch (\Throwable $e) {
                $enrichError = $e->getMessage();
            }
        }

        $contacts = $ticket->contacts();
        $selected = AbuseContact::selectedFor((int) $ticket->id());

        return $this->render('admin/ticket_detail', [
            'title' => $ticket->number(),
            'ticket' => $ticket,
            'contacts' => $contacts,
            'selectedContact' => $selected,
            'evidence' => $ticket->evidence(),
            'events' => $ticket->events(),
            'reports' => $ticket->reports(),
            'inbound' => $ticket->inboundMessages(),
            'enrichment' => $enrichment,
            'enrichError' => $enrichError,
            'statuses' => (array) Config::get('app.statuses', []),
            'formats' => ReportComposer::FORMATS,
            'defaultFormat' => (string) Config::get('app.reporter.default_format', 'arf'),
            'recipient' => $selected?->email() ?? ($contacts[0] ?? null)?->email() ?? '',
        ]);
    }

    public function enrich(Request $request, string $id): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        try {
            $data = $this->tickets->enrich($ticket, true);
            $count = count($ticket->contacts());
            Session::flash('success', sprintf(
                'Enrichment finished in %d ms — %d candidate contact(s).',
                (int) ($data['duration_ms'] ?? 0),
                $count
            ));
            if (!empty($data['errors'])) {
                Session::flash('warning', 'Some lookups failed: ' . implode('; ', array_slice((array) $data['errors'], 0, 3)));
            }
        } catch (\Throwable $e) {
            Session::flash('error', 'Enrichment failed: ' . $e->getMessage());
        }

        return $this->redirect('/admin/ticket/' . $ticket->id());
    }

    public function addContact(Request $request, string $id): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        $email = $request->string('abuse_email');
        $provider = $request->string('provider_name');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Please enter a valid abuse e-mail address.');

            return $this->redirect('/admin/ticket/' . $ticket->id() . '#contacts');
        }

        $contact = $this->tickets->addContact($ticket, $email, $provider);
        if ($contact === null) {
            Session::flash('error', 'The contact could not be added.');

            return $this->redirect('/admin/ticket/' . $ticket->id() . '#contacts');
        }

        Session::flash('success', 'Contact ' . $contact->email() . ' added.');

        return $this->redirect('/admin/ticket/' . $ticket->id() . '#contacts');
    }

    public function selectContact(Request $request, string $id, string $contactId): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        $contact = AbuseContact::find($this->requireInt($contactId));
        if ($contact === null || (int) $contact->get('ticket_id') !== (int) $ticket->id()) {
            return $this->notFound('Contact not found');
        }

        AbuseContact::select((int) $ticket->id(), (int) $contact->id());
        TicketEvent::log((int) $ticket->id(), 'contact_selected', 'Selected contact ' . $contact->email(), 'admin');
        Session::flash('success', 'Selected ' . $contact->email() . ' as the report recipient.');

        return $this->redirect('/admin/ticket/' . $ticket->id() . '#contacts');
    }

    public function deleteContact(Request $request, string $id, string $contactId): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        $contact = AbuseContact::find($this->requireInt($contactId));
        if ($ticket === null || $contact === null || (int) $contact->get('ticket_id') !== (int) $ticket->id()) {
            return $this->notFound('Contact not found');
        }

        AbuseContact::remove((int) $ticket->id(), (int) $contact->id());
        Session::flash('success', 'Contact removed.');

        return $this->redirect('/admin/ticket/' . $ticket->id() . '#contacts');
    }

    public function approve(Request $request, string $id): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        $contactId = (int) $request->input('contact_id', 0);
        if ($contactId < 1) {
            Session::flash('error', 'Select an abuse contact first.');

            return $this->redirect('/admin/ticket/' . $ticket->id() . '#contacts');
        }

        if (!$this->tickets->approve($ticket, $contactId)) {
            Session::flash('error', 'The ticket could not be approved (wrong status or contact).');

            return $this->redirect('/admin/ticket/' . $ticket->id());
        }

        $contact = AbuseContact::find($contactId);
        Session::flash('success', 'Ticket approved. Review the draft before sending.');

        return $this->redirect('/admin/ticket/' . $ticket->id() . '/preview?recipient=' . urlencode($contact?->email() ?? ''));
    }

    public function preview(Request $request, string $id): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        $selected = AbuseContact::selectedFor((int) $ticket->id());
        $contacts = $ticket->contacts();

        $fallbackRecipient = $selected?->email() ?? ($contacts[0] ?? null)?->email() ?? '';
        $recipient = (string) $request->query('recipient', $fallbackRecipient);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $recipient = $fallbackRecipient;
        }

        $format = (string) $request->query('format', (string) Config::get('app.reporter.default_format', 'arf'));
        if (!in_array($format, ReportComposer::FORMATS, true)) {
            $format = 'arf';
        }

        $subject = (string) $request->query('subject', '');
        $composed = $this->composer->compose(
            $ticket,
            $recipient,
            $format,
            $subject !== '' ? $subject : null,
            null
        );

        return $this->render('admin/arf_preview', [
            'title' => 'Preview report — ' . $ticket->number(),
            'ticket' => $ticket,
            'contacts' => $contacts,
            'recipient' => $recipient,
            'format' => $format,
            'formats' => ReportComposer::FORMATS,
            'composed' => $composed,
            'rawMime' => $composed['mime'],
            'humanText' => $composed['human_text'],
        ]);
    }

    public function send(Request $request, string $id): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        $recipient = $request->string('recipient_email');
        $format = $request->string('format', 'arf');
        $subject = $request->string('subject');
        $body = $request->string('body');

        $result = $this->tickets->sendReport(
            $ticket,
            $recipient,
            in_array($format, ReportComposer::FORMATS, true) ? $format : 'arf',
            $subject !== '' ? $subject : null,
            $body !== '' ? $body : null
        );

        if (!$result['ok']) {
            Session::flash('error', 'The report could not be sent: ' . (string) $result['error']);

            return $this->redirect('/admin/ticket/' . $ticket->id() . '/preview?recipient=' . urlencode($recipient));
        }

        Session::flash('success', 'Report sent to ' . $recipient . '. The reporter has been notified.');

        return $this->redirect('/admin/ticket/' . $ticket->id());
    }

    public function saveNotes(Request $request, string $id): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        $this->tickets->saveNotes($ticket, $request->string('admin_notes'));
        Session::flash('success', 'Internal notes saved.');

        return $this->redirect('/admin/ticket/' . $ticket->id() . '#notes');
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        $action = $request->string('action');
        $note = $request->string('note');

        switch ($action) {
            case 'resolve':
                $this->tickets->resolve($ticket, $note);
                Session::flash('success', 'Ticket resolved and the reporter notified.');
                break;
            case 'reject':
                $this->tickets->reject($ticket, $note);
                Session::flash('success', 'Ticket rejected.');
                break;
            case 'close':
                $this->tickets->close($ticket, $note);
                Session::flash('success', 'Ticket closed.');
                break;
            case 'reopen':
                $this->tickets->reopen($ticket);
                Session::flash('success', 'Ticket reopened.');
                break;
            default:
                Session::flash('error', 'Unknown action.');
        }

        return $this->redirect('/admin/ticket/' . $ticket->id());
    }

    public function uploadEvidence(Request $request, string $id): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        if ($ticket === null) {
            return $this->notFound('Ticket not found');
        }

        $files = $request->files('evidence');
        if ($files === []) {
            Session::flash('error', 'Choose a file to upload.');

            return $this->redirect('/admin/ticket/' . $ticket->id() . '#evidence');
        }

        $stored = 0;
        foreach ($files as $file) {
            try {
                $this->tickets->uploadEvidence($ticket, $file, 'admin');
                $stored++;
            } catch (\Throwable $e) {
                Session::flash('error', $e->getMessage());
                break;
            }
        }

        if ($stored > 0) {
            Session::flash('success', sprintf('%d file(s) attached.', $stored));
        }

        return $this->redirect('/admin/ticket/' . $ticket->id() . '#evidence');
    }

    public function downloadEvidence(Request $request, string $id, string $evidenceId): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        $evidence = Evidence::find($this->requireInt($evidenceId));
        if ($ticket === null || $evidence === null || (int) $evidence->get('ticket_id') !== (int) $ticket->id()) {
            return $this->notFound('Evidence not found');
        }
        if (!$evidence->exists()) {
            return $this->notFound('The stored file is missing');
        }

        $contents = (string) file_get_contents($evidence->absolutePath());

        return (new Response())
            ->download($evidence->originalName(), (string) ($evidence->get('mime_type') ?: 'application/octet-stream'))
            ->raw($contents);
    }

    public function downloadReport(Request $request, string $id, string $reportId): Response
    {
        $ticket = Ticket::find($this->requireInt($id));
        $report = OutboundReport::find($this->requireInt($reportId));
        if ($ticket === null || $report === null || (int) $report->get('ticket_id') !== (int) $ticket->id()) {
            return $this->notFound('Report not found');
        }

        return (new Response())
            ->download($ticket->number() . '-' . $report->get('format') . '.eml', 'message/rfc822')
            ->raw($report->rawMime());
    }

    public function settings(Request $request): Response
    {
        $mailConfig = (array) Config::get('mail', []);
        $imap = (array) ($mailConfig['imap'] ?? []);
        $smtp = (array) ($mailConfig['smtp'] ?? []);

        return $this->render('admin/settings', [
            'title' => 'Settings',
            'admins' => Admin::allAdmins(),
            'currentAdminId' => Session::adminId(),
            'smtp' => [
                'transport' => (string) ($mailConfig['transport'] ?? 'smtp'),
                'from' => (string) ($mailConfig['from'] ?? ''),
                'host' => (string) ($smtp['host'] ?? ''),
                'port' => (string) ($smtp['port'] ?? ''),
                'encryption' => (string) ($smtp['encryption'] ?? ''),
                'username' => (string) ($smtp['username'] ?? ''),
                'configured' => (string) ($smtp['host'] ?? '') !== '',
            ],
            'imap' => [
                'host' => (string) ($imap['host'] ?? ''),
                'port' => (string) ($imap['port'] ?? ''),
                'encryption' => (string) ($imap['encryption'] ?? ''),
                'username' => (string) ($imap['username'] ?? ''),
                'intake_mailbox' => (string) ($imap['intake_mailbox'] ?? ''),
                'response_mailbox' => (string) ($imap['response_mailbox'] ?? ''),
                'configured' => (string) ($imap['host'] ?? '') !== '',
            ],
            'piggyback' => $this->piggyback->status(),
            'system' => [
                'php' => PHP_VERSION,
                'extensions' => [
                    'pdo_pgsql' => extension_loaded('pdo_pgsql'),
                    'openssl' => extension_loaded('openssl'),
                    'mbstring' => extension_loaded('mbstring'),
                    'fileinfo' => extension_loaded('fileinfo'),
                    'imap' => extension_loaded('imap'),
                ],
                'shell_exec' => (new EnrichmentService())->whoisUnavailableReason() === null,
                'whois_reason' => (new EnrichmentService())->whoisUnavailableReason(),
                'upload_dir' => (string) Config::get('app.uploads.path', ''),
                'upload_writable' => is_writable((string) Config::get('app.uploads.path', '')),
                'state' => \App\Models\SystemState::all(),
            ],
            'inboxStats' => InboundMessage::stats(),
        ]);
    }

    public function addAdmin(Request $request): Response
    {
        $email = $request->string('email');
        $admin = Admin::createAdmin($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Please enter a valid e-mail address.');

            return $this->redirect('/admin/settings#admins');
        }

        Session::flash('success', 'Administrator ' . $admin->email() . ' added. They sign in with a one-time code, there is no password to set.');

        return $this->redirect('/admin/settings#admins');
    }

    public function toggleAdmin(Request $request, string $id): Response
    {
        $adminId = $this->requireInt($id);
        $admin = Admin::find($adminId);
        if ($admin === null) {
            return $this->notFound('Administrator not found');
        }

        $active = $request->string('active') === '1';

        $auth = new \App\Services\AuthService();
        if (!$auth->toggleAdmin($adminId, $active)) {
            Session::flash('error', 'You cannot disable the account you are signed in with.');
        } else {
            Session::flash('success', ($active ? 'Enabled ' : 'Disabled ') . $admin->email() . '.');
        }

        return $this->redirect('/admin/settings#admins');
    }

    public function sendTestMail(Request $request): Response
    {
        $adminEmail = (string) Session::adminEmail();
        $result = $this->mailer->sendText(
            $adminEmail,
            'Abuse Report Desk test message',
            "This is a test message from your Abuse Report Desk.\n\n"
            . "Sent at: " . date('c') . "\n"
            . "Transport: " . (string) Config::get('mail.transport', 'smtp') . "\n\n"
            . "If you can read this, outgoing mail works."
        );

        if ($result['ok']) {
            Session::flash('success', 'Test message sent to ' . $adminEmail . ' via ' . $result['transport'] . '.');
        } else {
            Session::flash('error', 'Test message failed: ' . (string) $result['error']);
        }

        return $this->redirect('/admin/settings#mail');
    }

    public function checkMailboxes(Request $request): Response
    {
        $intake = $this->mailbox->checkIntake(true);
        $responses = $this->mailbox->checkResponses(true);

        $messages = [];
        foreach (['Intake' => $intake, 'Responses' => $responses] as $label => $result) {
            if ($result['ok']) {
                $messages[] = sprintf('%s: %d fetched, %d new/matched', $label, $result['fetched'], $result['created']);
            } else {
                $messages[] = sprintf('%s failed: %s', $label, implode('; ', $result['errors']));
            }
        }

        $failed = !$intake['ok'] && !$responses['ok'];
        Session::flash($failed ? 'error' : 'success', implode(' — ', $messages));

        return $this->redirect('/admin/settings#mail');
    }
}
