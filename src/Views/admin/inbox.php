<?php
/**
 * @var list<\App\Models\InboundMessage> $messages
 * @var string $mailbox
 * @var bool $unmatchedOnly
 * @var array{intake: int, responses: int, unmatched: int} $stats
 * @var array<string, mixed> $piggyback
 * @var bool $imapConfigured
 * @var string $csrf
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Mailboxes</p>
        <h1>Inbox</h1>
        <p class="muted">
            <?= (int) $stats['responses'] ?> reply message(s) · <?= (int) $stats['intake'] ?> intake message(s) ·
            <?= (int) $stats['unmatched'] ?> unmatched
        </p>
    </div>
    <form method="post" action="/admin/inbox/check" class="actions">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="mailbox" value="both">
        <button class="btn btn-primary" type="submit"<?= $imapConfigured ? '' : ' disabled title="IMAP is not configured"' ?>>
            Check now
        </button>
    </form>
</div>

<?php if (!$imapConfigured): ?>
    <div class="alert alert-warning">
        IMAP is not configured. Set <code>IMAP_HOST</code>, <code>IMAP_USER</code> and
        <code>IMAP_PASSWORD</code> to pull inbound mail. Intake and reply tracking stay disabled until then.
    </div>
<?php endif; ?>

<section class="card">
    <div class="tabs-row">
        <div class="segmented">
            <a class="seg<?= $mailbox === 'responses' ? ' is-active' : '' ?>" href="/admin/inbox?mailbox=responses<?= $unmatchedOnly ? '&unmatched=1' : '' ?>">Provider replies</a>
            <a class="seg<?= $mailbox === 'intake' ? ' is-active' : '' ?>" href="/admin/inbox?mailbox=intake<?= $unmatchedOnly ? '&unmatched=1' : '' ?>">Intake</a>
            <a class="seg<?= $mailbox === 'all' ? ' is-active' : '' ?>" href="/admin/inbox?mailbox=all">Everything</a>
        </div>
        <label class="toggle">
            <input type="checkbox" onchange="window.location='?mailbox=<?= e($mailbox) ?><?= $unmatchedOnly ? '' : '&unmatched=1' ?>'"
                <?= $unmatchedOnly ? 'checked' : '' ?>>
            Only unmatched
        </label>
    </div>

    <?php if ($messages === []): ?>
        <p class="muted">
            No messages yet. Messages are pulled lazily when an admin page loads (at most once every
            <?= (int) round(((int) \App\Core\Config::get('app.piggyback.imap_interval', 300)) / 60) ?> minutes),
            or immediately with “Check now”.
        </p>
    <?php else: ?>
        <ul class="message-list">
            <?php foreach ($messages as $message): ?>
                <li class="<?= $message->isMatched() ? '' : 'is-unmatched' ?>">
                    <a class="message-link" href="/admin/inbox/<?= (int) $message->id() ?>">
                        <div class="message-line">
                            <span class="message-from mono"><?= e($message->from()) ?></span>
                            <span class="muted small"><?= e(time_ago((string) $message->get('received_at'))) ?></span>
                        </div>
                        <div class="message-line">
                            <span class="message-subject"><?= e($message->subject()) ?></span>
                            <?php if ($message->isMatched()): ?>
                                <span class="badge badge-reported">ticket #<?= e((string) $message->get('ticket_id')) ?></span>
                            <?php else: ?>
                                <span class="badge badge-new">unmatched</span>
                            <?php endif; ?>
                        </div>
                        <p class="message-excerpt muted small"><?= e(str_limit($message->bodyText(), 160)) ?></p>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!empty($piggyback['ran'])): ?>
        <p class="muted small">
            Background check in this request:
            <?php foreach ((array) $piggyback['ran'] as $kind => $result): ?>
                <?= e((string) $kind) ?> <?= (int) ($result['fetched'] ?? 0) ?> fetched<?= $kind !== 'responses' ? ' · ' : '' ?>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</section>
