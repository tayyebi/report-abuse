<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\SystemState;
use App\Models\Ticket;

/**
 * "No cron" scheduling: work happens inside admin HTTP requests, at most once
 * per configured interval, with a hard time budget.
 */
final class PiggybackService
{
    public function __construct(
        private readonly ?MailboxProcessor $mailbox = null,
        private readonly ?TicketService $tickets = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function runOnAdminLoad(bool $includeEnrichment = true): array
    {
        $started = microtime(true);
        $budget = max(3, (int) Config::get('app.piggyback.time_budget', 20));
        $interval = max(30, (int) Config::get('app.piggyback.imap_interval', 300));

        $summary = [
            'imap_configured' => ImapClient::isConfigured(),
            'ran' => [],
            'errors' => [],
        ];

        if ($summary['imap_configured']) {
            $mailbox = $this->mailbox ?? new MailboxProcessor();
            $checks = [
                'intake' => 'last_intake_check',
                'responses' => 'last_response_check',
            ];

            foreach ($checks as $kind => $stateKey) {
                if ((microtime(true) - $started) > $budget) {
                    break;
                }
                if (SystemState::isFresh($stateKey, $interval)) {
                    continue;
                }

                // Claim the slot first so parallel page loads do not stampede.
                SystemState::touch($stateKey);

                try {
                    $result = $kind === 'intake' ? $mailbox->checkIntake(true) : $mailbox->checkResponses(true);
                    $summary['ran'][$kind] = $result;
                    if (!$result['ok']) {
                        $summary['errors'][] = $kind . ': ' . implode('; ', $result['errors']);
                    }
                } catch (\Throwable $e) {
                    $summary['errors'][] = $kind . ': ' . $e->getMessage();
                }
            }
        }

        if ($includeEnrichment && (microtime(true) - $started) < $budget) {
            $summary['enriched'] = $this->enrichPending($budget - (microtime(true) - $started));
        }

        $summary['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        return $summary;
    }

    /**
     * Lazily enrich a few new tickets.
     *
     * @return list<string>
     */
    public function enrichPending(float $remainingSeconds = 10): array
    {
        $batch = max(0, (int) Config::get('app.piggyback.enrichment_batch', 3));
        if ($batch === 0) {
            return [];
        }

        $tickets = $this->tickets ?? new TicketService();
        $done = [];
        $started = microtime(true);

        foreach (Ticket::needingEnrichment($batch) as $ticket) {
            if ((microtime(true) - $started) > max(2, $remainingSeconds)) {
                break;
            }
            try {
                $tickets->enrich($ticket);
                $done[] = $ticket->number();
            } catch (\Throwable $e) {
                $this->log('Enrichment failed for ' . $ticket->number() . ': ' . $e->getMessage());
            }
        }

        return $done;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'last_intake_check' => SystemState::get('last_intake_check'),
            'last_response_check' => SystemState::get('last_response_check'),
            'last_intake_report' => SystemState::getJson('last_intake_report'),
            'last_response_report' => SystemState::getJson('last_response_report'),
            'imap_configured' => ImapClient::isConfigured(),
        ];
    }

    private function log(string $message): void
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/app.log', sprintf("[%s] piggyback: %s\n", date('c'), $message), FILE_APPEND);
    }
}
