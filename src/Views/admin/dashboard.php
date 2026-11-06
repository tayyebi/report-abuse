<?php
/**
 * @var array<string, int> $counts
 * @var int $total
 * @var list<\App\Models\Ticket> $recent
 * @var list<\App\Models\Ticket> $awaiting
 * @var int $newToday
 * @var array<string, mixed> $piggyback
 * @var array{intake: int, responses: int, unmatched: int} $inboxStats
 * @var array<string, string> $statuses
 * @var bool $imapConfigured
 * @var string $csrf
 */
$open = ($counts['new'] ?? 0) + ($counts['approved'] ?? 0) + ($counts['reported'] ?? 0);
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Overview</p>
        <h1>Dashboard</h1>
        <p class="muted">
            <?= (int) $newToday ?> report(s) arrived today ·
            <?= (int) ($piggyback['duration_ms'] ?? 0) ?> ms of background work in this request
        </p>
    </div>
    <div class="actions">
        <form method="post" action="/admin/inbox/check" class="inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="mailbox" value="both">
            <button class="btn btn-ghost" type="submit"<?= $imapConfigured ? '' : ' disabled title="IMAP is not configured"' ?>>
                Check mailboxes
            </button>
        </form>
        <a class="btn btn-primary" href="/admin/tickets?status=new">Review new (<?= (int) ($counts['new'] ?? 0) ?>)</a>
    </div>
</div>

<section class="stats">
    <a class="stat card" href="/admin/tickets?status=new">
        <span class="stat-label">New</span>
        <span class="stat-value"><?= (int) ($counts['new'] ?? 0) ?></span>
        <span class="stat-meta">awaiting triage</span>
    </a>
    <a class="stat card" href="/admin/tickets?status=approved">
        <span class="stat-label">Approved</span>
        <span class="stat-value"><?= (int) ($counts['approved'] ?? 0) ?></span>
        <span class="stat-meta">ready to send</span>
    </a>
    <a class="stat card" href="/admin/tickets?status=reported">
        <span class="stat-label">Reported</span>
        <span class="stat-value"><?= (int) ($counts['reported'] ?? 0) ?></span>
        <span class="stat-meta">waiting for providers</span>
    </a>
    <a class="stat card" href="/admin/tickets?status=resolved">
        <span class="stat-label">Resolved</span>
        <span class="stat-value"><?= (int) ($counts['resolved'] ?? 0) ?></span>
        <span class="stat-meta">closed successfully</span>
    </a>
    <a class="stat card" href="/admin/inbox?mailbox=responses&unmatched=1">
        <span class="stat-label">Inbox</span>
        <span class="stat-value"><?= (int) $inboxStats['unmatched'] ?></span>
        <span class="stat-meta">unmatched replies</span>
    </a>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h2>Latest reports</h2>
            <a class="link small" href="/admin/tickets">All <?= (int) $total ?> tickets →</a>
        </div>

        <?php if ($recent === []): ?>
            <p class="muted">No tickets yet. Submitted reports will appear here.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr><th>Ticket</th><th>Target</th><th>Status</th><th>Age</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $ticket): ?>
                    <tr>
                        <td>
                            <a href="/admin/ticket/<?= (int) $ticket->id() ?>"><?= e($ticket->number()) ?></a>
                            <span class="muted small block"><?= abuse_icon($ticket->abuseType()) ?> <?= e($ticket->abuseTypeLabel()) ?></span>
                        </td>
                        <td class="break"><?= e($ticket->target()) ?></td>
                        <td><span class="badge <?= status_class($ticket->status()) ?>"><?= e($ticket->statusLabel()) ?></span></td>
                        <td class="muted small"><?= e(time_ago((string) $ticket->get('created_at'))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Waiting for a provider reply</h2>
        <?php if ($awaiting === []): ?>
            <p class="muted">Nothing is pending a reply right now.</p>
        <?php else: ?>
            <ul class="plain-list">
                <?php foreach ($awaiting as $ticket): ?>
                    <li>
                        <a href="/admin/ticket/<?= (int) $ticket->id() ?>"><?= e($ticket->number()) ?></a>
                        <span class="muted small block">
                            <?= e($ticket->target()) ?> · sent <?= e(time_ago((string) $ticket->get('reported_at'))) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>Background work</h3>
        <dl class="summary-list compact">
            <div>
                <dt>Intake mailbox</dt>
                <dd>
                    <?= e(time_ago((string) ($piggyback['last_intake_check'] ?? null))) ?>
                    <?php if (!empty($piggyback['last_intake_report']['fetched'])): ?>
                        <span class="muted small">(<?= (int) $piggyback['last_intake_report']['fetched'] ?> fetched)</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Response mailbox</dt>
                <dd><?= e(time_ago((string) ($piggyback['last_response_check'] ?? null))) ?></dd>
            </div>
            <div>
                <dt>IMAP</dt>
                <dd>
                    <?php if ($imapConfigured): ?>
                        <span class="badge badge-resolved">configured</span>
                    <?php else: ?>
                        <span class="badge badge-other">disabled</span>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
        <?php if (!empty($piggyback['enriched'])): ?>
            <p class="muted small">Enriched in this request: <?= e(implode(', ', (array) $piggyback['enriched'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($piggyback['errors'])): ?>
            <p class="field-error small"><?= e(implode('; ', (array) $piggyback['errors'])) ?></p>
        <?php endif; ?>
        <p class="muted small">
            There is no cron job: mailbox polling and enrichment run inside page loads, at most
            once per configured interval.
        </p>
    </section>
</div>
