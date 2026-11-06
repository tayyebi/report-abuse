<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\InboundMessage;
use App\Models\Ticket;
use App\Services\TicketService;

/**
 * Public report form and reporter-facing status page.
 */
final class PublicController extends Controller
{
    public function __construct(private readonly TicketService $tickets = new TicketService())
    {
    }

    public function showReportForm(Request $request): Response
    {
        return $this->render('public/report_form', [
            'title' => 'Report abuse',
            'abuseTypes' => (array) Config::get('app.abuse_types', []),
            'errors' => $this->oldErrors(),
            'old' => $this->oldInput(),
            'maxFiles' => (int) Config::get('app.intake.max_evidence_files', 5),
            'maxFileSize' => format_bytes((int) Config::get('app.uploads.max_size', 10485760)),
        ]);
    }

    public function submitReport(Request $request): Response
    {
        // Honeypot: bots fill every field they can see.
        if (trim($request->string('website')) !== '') {
            Session::flash('success', 'Thank you, your report has been received.');

            return $this->redirect('/');
        }

        $limit = (int) Config::get('app.intake.rate_limit_per_hour', 10);
        if ($limit > 0 && $this->tickets->recentSubmissionCount($request->ip()) >= $limit) {
            Session::flash('error', 'Too many reports were submitted from this connection. Please try again later.');

            return $this->redirect('/');
        }

        $input = [
            'abuse_type' => $request->string('abuse_type', 'other'),
            'target' => $request->string('target'),
            'reported_url' => $request->string('reported_url'),
            'reported_domain' => $request->string('reported_domain'),
            'reported_ip' => $request->string('reported_ip'),
            'reporter_email' => $request->string('reporter_email'),
            'reporter_name' => $request->string('reporter_name'),
            'description' => $request->string('description'),
        ];

        $result = $this->tickets->submitWeb($input, $request->files('evidence'), $request->ip());

        if ($result['ticket'] === null) {
            return $this->validationFailed($result['errors'], '/');
        }

        $ticket = $result['ticket'];
        Session::flash('success', 'Thank you — your report was received.');

        return $this->redirect('/report/submitted/' . $ticket->token());
    }

    public function showSubmitted(Request $request, string $token): Response
    {
        $ticket = Ticket::findByToken($token);
        if ($ticket === null) {
            return $this->notFound('Unknown report');
        }

        return $this->render('public/report_submitted', [
            'title' => 'Report received',
            'ticket' => $ticket,
            'statusUrl' => '/ticket/' . $ticket->token(),
        ]);
    }

    public function showTicket(Request $request, string $token): Response
    {
        $ticket = Ticket::findByToken($token);
        if ($ticket === null) {
            return $this->notFound('Unknown report');
        }

        $reports = array_values(array_filter(
            $ticket->reports(),
            static fn ($report): bool => $report->isSent()
        ));

        $responses = array_values(array_filter(
            $ticket->inboundMessages(),
            static fn (InboundMessage $message): bool => $message->get('mailbox') === 'responses'
        ));

        $timeline = [];
        $timeline[] = ['at' => $ticket->get('created_at'), 'label' => 'Report received', 'done' => true];
        $timeline[] = [
            'at' => $ticket->get('approved_at'),
            'label' => 'Reviewed by an analyst',
            'done' => $ticket->get('approved_at') !== null || $ticket->get('reported_at') !== null || $ticket->status() === Ticket::STATUS_RESOLVED,
        ];
        $timeline[] = [
            'at' => $ticket->get('reported_at'),
            'label' => 'Forwarded to the hosting provider',
            'done' => $ticket->get('reported_at') !== null,
        ];
        $timeline[] = [
            'at' => $ticket->get('resolved_at'),
            'label' => 'Resolved',
            'done' => $ticket->status() === Ticket::STATUS_RESOLVED,
        ];

        return $this->render('public/ticket_status', [
            'title' => 'Report ' . $ticket->number(),
            'ticket' => $ticket,
            'reports' => $reports,
            'responses' => $responses,
            'timeline' => $timeline,
            'evidence' => $ticket->evidence(),
        ]);
    }

    public function addEvidence(Request $request, string $token): Response
    {
        $ticket = Ticket::findByToken($token);
        if ($ticket === null) {
            return $this->notFound('Unknown report');
        }
        if (!$ticket->isOpen()) {
            Session::flash('warning', 'This report is closed, so no further files can be added.');

            return $this->redirect('/ticket/' . $ticket->token());
        }

        $files = $request->files('evidence');
        if ($files === []) {
            Session::flash('error', 'Please choose a file to upload.');

            return $this->redirect('/ticket/' . $ticket->token());
        }

        $stored = 0;
        foreach ($files as $file) {
            try {
                $this->tickets->uploadEvidence($ticket, $file, 'reporter');
                $stored++;
            } catch (\Throwable $e) {
                Session::flash('error', $e->getMessage());
                break;
            }
        }

        if ($stored > 0) {
            Session::flash('success', sprintf('%d file(s) added to your report.', $stored));
        }

        return $this->redirect('/ticket/' . $ticket->token());
    }
}
