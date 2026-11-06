<?php
/**
 * @var \App\Models\Ticket $ticket
 * @var string $statusUrl
 */
?>
<section class="card narrow center success-card">
    <div class="success-icon">✓</div>
    <h1>Thank you — report received</h1>
    <p class="lead">
        Your report was registered as <strong><?= e($ticket->number()) ?></strong>.
        A confirmation e-mail is on its way to <strong><?= e((string) $ticket->get('reporter_email')) ?></strong>.
    </p>

    <div class="copy-box">
        <span class="copy-label">Private status link</span>
        <input type="text" readonly value="<?= e($statusUrl) ?>" data-copy-source>
        <button type="button" class="btn btn-sm" data-copy>Copy</button>
    </div>
    <p class="muted small">
        Keep this link private — anyone who has it can read the status of your report.
    </p>

    <dl class="summary-list">
        <div><dt>Type</dt><dd><?= e($ticket->abuseTypeLabel()) ?></dd></div>
        <div><dt>Target</dt><dd><?= e($ticket->target()) ?></dd></div>
        <div><dt>Status</dt><dd><span class="badge badge-new">Queued for review</span></dd></div>
    </dl>

    <div class="actions center">
        <a class="btn btn-primary" href="<?= e($statusUrl) ?>">Track this report</a>
        <a class="btn btn-ghost" href="/">Report something else</a>
    </div>
</section>
