<?php
/**
 * @var \App\Models\Ticket $ticket
 * @var list<\App\Models\OutboundReport> $reports
 * @var list<\App\Models\InboundMessage> $responses
 * @var list<array{at: ?string, label: string, done: bool}> $timeline
 * @var list<\App\Models\Evidence> $evidence
 * @var string $csrf
 */
$statusClass = 'badge-' . e($ticket->status());
?>
<section class="card">
    <div class="ticket-head">
        <div>
            <p class="eyebrow">Report status</p>
            <h1><?= e($ticket->number()) ?></h1>
            <p class="muted">
                <?= e($ticket->abuseTypeLabel()) ?> · <?= e($ticket->target()) ?>
            </p>
        </div>
        <span class="badge <?= $statusClass ?> badge-lg"><?= e($ticket->statusLabel()) ?></span>
    </div>

    <ol class="timeline">
        <?php foreach ($timeline as $step): ?>
            <li class="<?= $step['done'] ? 'is-done' : '' ?>">
                <span class="dot"></span>
                <div>
                    <strong><?= e($step['label']) ?></strong>
                    <span class="muted small"><?= $step['done'] ? e((string) ($step['at'] ?? 'completed')) : 'pending' ?></span>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<div class="grid-2 stretch">
    <section class="card">
        <h2>What you reported</h2>
        <dl class="summary-list">
            <div><dt>Type</dt><dd><?= e($ticket->abuseTypeLabel()) ?></dd></div>
            <div><dt>URL</dt><dd class="break"><?= e((string) ($ticket->url() ?? '—')) ?></dd></div>
            <div><dt>Domain</dt><dd><?= e((string) ($ticket->domain() ?? '—')) ?></dd></div>
            <div><dt>IP address</dt><dd><?= e((string) ($ticket->ip() ?? '—')) ?></dd></div>
            <div><dt>Submitted</dt><dd><?= e((string) $ticket->get('created_at')) ?></dd></div>
        </dl>

        <h3>Description</h3>
        <p class="pre-line muted"><?= e((string) $ticket->get('description')) ?></p>

        <?php if ($evidence !== []): ?>
            <h3>Evidence attached</h3>
            <ul class="plain-list">
                <?php foreach ($evidence as $file): ?>
                    <li>
                        <span class="file-icon">📎</span>
                        <?= e($file->originalName()) ?>
                        <span class="muted small">(<?= e(format_bytes($file->size())) ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Progress</h2>

        <h3>Reports forwarded</h3>
        <?php if ($reports === []): ?>
            <p class="muted">No report has been forwarded yet. Our analyst is still reviewing this ticket.</p>
        <?php else: ?>
            <ul class="plain-list">
                <?php foreach ($reports as $report): ?>
                    <li>
                        <strong><?= e($report->recipient()) ?></strong>
                        <span class="muted small"><?= e((string) $report->get('sent_at')) ?></span>
                        <span class="badge badge-reported"><?= e(strtoupper((string) $report->get('format'))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>Replies from the provider</h3>
        <?php if ($responses === []): ?>
            <p class="muted">No reply has been received yet.</p>
        <?php else: ?>
            <?php foreach ($responses as $message): ?>
                <article class="message-card">
                    <header>
                        <strong><?= e($message->from()) ?></strong>
                        <span class="muted small"><?= e((string) $message->get('received_at')) ?></span>
                    </header>
                    <p class="message-subject"><?= e($message->subject()) ?></p>
                    <pre class="message-body"><?= e(str_limit($message->bodyText(), 2000, '…')) ?></pre>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($ticket->isOpen()): ?>
            <h3>Add more evidence</h3>
            <form method="post" action="/ticket/<?= e($ticket->token()) ?>/evidence" enctype="multipart/form-data" class="stack-sm">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="file" name="evidence[]" multiple required>
                <button class="btn btn-sm" type="submit">Upload files</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<p class="muted small center">
    Keep this page's address private. Questions? Reply to the confirmation e-mail and mention <?= e($ticket->number()) ?>.
</p>
