<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\InboundMessage;
use App\Models\Ticket;
use App\Services\EmailParser;
use App\Services\ImapClient;
use App\Services\MailboxProcessor;
use App\Services\PiggybackService;
use App\Services\TicketService;

/**
 * Inbox views: intake messages and abuse-desk replies.
 */
final class InboxController extends Controller
{
    public function __construct(
        private readonly MailboxProcessor $mailbox = new MailboxProcessor(),
        private readonly TicketService $tickets = new TicketService(),
        private readonly PiggybackService $piggyback = new PiggybackService()
    ) {
    }

    public function index(Request $request): Response
    {
        $mailbox = (string) $request->query('mailbox', 'responses');
        $unmatchedOnly = $request->query('unmatched') === '1';
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 30;

        // Piggyback: refresh the mailboxes when the cached check is stale.
        $piggyback = $this->piggyback->runOnAdminLoad(false);

        $messages = InboundMessage::recent($mailbox, $perPage, $unmatchedOnly);
        $stats = InboundMessage::stats();

        return $this->render('admin/inbox', [
            'title' => 'Inbox',
            'messages' => $messages,
            'mailbox' => $mailbox,
            'unmatchedOnly' => $unmatchedOnly,
            'stats' => $stats,
            'piggyback' => $piggyback,
            'imapConfigured' => ImapClient::isConfigured(),
            'page' => $page,
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $message = InboundMessage::find($this->requireInt($id));
        if ($message === null) {
            return $this->notFound('Message not found');
        }

        $ticket = $message->get('ticket_id') !== null ? Ticket::find($message->get('ticket_id')) : null;

        // Suggest tickets: same sender domain / ticket number in subject.
        $suggestions = [];
        $from = $message->from();
        if (str_contains($from, '@')) {
            $domain = substr($from, strpos($from, '@') + 1);
            $suggestions = Ticket::search(['q' => $domain], 5);
        }
        if (preg_match('/ABR-\d{8}-\d{4,}/i', $message->subject(), $m)) {
            $byNumber = Ticket::findByNumber(strtoupper($m[0]));
            if ($byNumber !== null) {
                array_unshift($suggestions, $byNumber);
            }
        }

        $recentTickets = Ticket::search([], 15);

        return $this->render('admin/inbox_message', [
            'title' => $message->subject(),
            'message' => $message,
            'ticket' => $ticket,
            'suggestions' => array_slice($suggestions, 0, 5),
            'recentTickets' => $recentTickets,
            'canCreateTicket' => $message->get('mailbox') === 'intake' || !$message->isMatched(),
            'mailboxes' => [
                'intake' => (string) Config::get('mail.imap.intake_mailbox', 'INBOX'),
                'responses' => (string) Config::get('mail.imap.response_mailbox', 'INBOX'),
            ],
        ]);
    }

    public function check(Request $request): Response
    {
        $mailbox = $request->string('mailbox', 'both');

        $results = [];
        if ($mailbox === 'intake' || $mailbox === 'both') {
            $results['Intake'] = $this->mailbox->checkIntake(true);
        }
        if ($mailbox === 'responses' || $mailbox === 'both') {
            $results['Responses'] = $this->mailbox->checkResponses(true);
        }

        $parts = [];
        $failed = false;
        foreach ($results as $label => $result) {
            if ($result['ok']) {
                $parts[] = sprintf('%s: %d message(s) fetched, %d new', $label, $result['fetched'], $result['created']);
            } else {
                $failed = true;
                $parts[] = sprintf('%s failed: %s', $label, implode('; ', $result['errors']));
            }
        }

        Session::flash($failed ? 'error' : 'success', implode(' — ', $parts));

        return $this->redirect('/admin/inbox?mailbox=' . ($mailbox === 'intake' ? 'intake' : 'responses'));
    }

    public function link(Request $request, string $id): Response
    {
        $message = InboundMessage::find($this->requireInt($id));
        if ($message === null) {
            return $this->notFound('Message not found');
        }

        $ticketRef = $request->string('ticket');
        $ticket = ctype_digit($ticketRef) ? Ticket::find((int) $ticketRef) : Ticket::findByNumber($ticketRef);
        if ($ticket === null) {
            Session::flash('error', 'That ticket could not be found.');

            return $this->redirect('/admin/inbox/' . $message->id());
        }

        $message->linkTo((int) $ticket->id());
        \App\Models\TicketEvent::log(
            (int) $ticket->id(),
            'response_linked',
            'Inbound message linked manually: ' . str_limit($message->subject(), 120),
            'admin',
            ['inbound_id' => $message->id()]
        );
        Session::flash('success', 'Message linked to ' . $ticket->number() . '.');

        return $this->redirect('/admin/ticket/' . $ticket->id() . '#responses');
    }

    public function createTicket(Request $request, string $id): Response
    {
        $message = InboundMessage::find($this->requireInt($id));
        if ($message === null) {
            return $this->notFound('Message not found');
        }

        if ($message->get('ticket_id') !== null) {
            Session::flash('warning', 'This message is already linked to a ticket.');

            return $this->redirect('/admin/inbox/' . $message->id());
        }

        $raw = (string) $message->get('raw_mime', '');
        if ($raw === '') {
            Session::flash('error', 'The original message body is not stored, so a ticket cannot be created.');

            return $this->redirect('/admin/inbox/' . $message->id());
        }

        $parsed = EmailParser::parse($raw, (string) $message->get('received_at', ''));
        $ticket = $this->tickets->createFromEmail($parsed, (string) $message->get('mailbox', 'intake'));

        if ($ticket === null) {
            Session::flash('error', 'No URL, domain or IP address could be found in this message.');

            return $this->redirect('/admin/inbox/' . $message->id());
        }

        $message->linkTo((int) $ticket->id());
        Session::flash('success', 'Ticket ' . $ticket->number() . ' created from the message.');

        return $this->redirect('/admin/ticket/' . $ticket->id());
    }
}
