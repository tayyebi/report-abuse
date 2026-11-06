<?php
/**
 * @var \App\Models\InboundMessage $message
 * @var ?\App\Models\Ticket $ticket
 * @var list<\App\Models\Ticket> $suggestions
 * @var list<\App\Models\Ticket> $recentTickets
 * @var bool $canCreateTicket
 * @var array<string, string> $mailboxes
 * @var string $csrf
 */
?>
<nav class="breadcrumb">
    <a href="/admin/inbox">Inbox</a> <span>/</span> <span><?= e(str_limit($message->subject(), 60)) ?></span>
</nav>

<div class="page-head">
    <div>
        <p class="eyebrow"><?= e((string) $message->get('mailbox')) ?> mailbox</p>
        <h1><?= e($message->subject()) ?></h1>
        <p class="muted break">
            From <span class="mono"><?= e($message->from()) ?></span>
            · received <?= e((string) $message->get('received_at')) ?>
            · <?= e((string) ($mailboxes[(string) $message->get('mailbox')] ?? '')) ?>
        </p>
    </div>
    <div class="actions">
        <?php if ($ticket !== null): ?>
            <a class="btn btn-primary" href="/admin/ticket/<?= (int) $ticket->id() ?>">Open <?= e($ticket->number()) ?></a>
        <?php endif; ?>
        <?php if ($canCreateTicket): ?>
            <form method="post" action="/admin/inbox/<?= (int) $message->id() ?>/create-ticket" class="inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn-ghost" type="submit" data-confirm="Create a new ticket from this message?">Create ticket</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-main">
        <section class="card">
            <h2>Message</h2>
            <dl class="summary-list compact">
                <div><dt>From</dt><dd class="mono break"><?= e($message->from()) ?></dd></div>
                <div><dt>To</dt><dd class="mono break"><?= e((string) $message->get('to_address')) ?></dd></div>
                <div><dt>Message-ID</dt><dd class="mono small break"><?= e((string) $message->get('message_id')) ?></dd></div>
                <div><dt>In reply to</dt><dd class="mono small break"><?= e((string) ($message->get('in_reply_to') ?? '—')) ?></dd></div>
                <div><dt>IMAP UID</dt><dd class="mono"><?= e((string) $message->get('imap_uid')) ?></dd></div>
            </dl>

            <?php if ($message->bodyText() !== ''): ?>
                <pre class="message-body tall"><?= e($message->bodyText()) ?></pre>
            <?php elseif ($message->get('body_html')): ?>
                <pre class="code-block tall"><?= e(str_limit((string) $message->get('body_html'), 8000, '…')) ?></pre>
            <?php else: ?>
                <p class="muted">The message has no text body.</p>
            <?php endif; ?>

            <?php if ($message->get('error_message')): ?>
                <p class="field-error"><?= e((string) $message->get('error_message')) ?></p>
            <?php endif; ?>
        </section>
    </div>

    <aside class="detail-side">
        <section class="card">
            <h2>Link to a ticket</h2>
            <form method="post" action="/admin/inbox/<?= (int) $message->id() ?>/link" class="stack-sm">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input name="ticket" type="text" placeholder="ABR-20261106-0001 or ticket id" required list="ticket-options">
                <datalist id="ticket-options">
                    <?php foreach ($recentTickets as $candidate): ?>
                        <option value="<?= e($candidate->number()) ?>"><?= e($candidate->target()) ?></option>
                    <?php endforeach; ?>
                </datalist>
                <button class="btn btn-primary btn-block" type="submit">Link message</button>
            </form>

            <?php if ($suggestions !== []): ?>
                <h3>Suggested tickets</h3>
                <ul class="plain-list">
                    <?php foreach ($suggestions as $candidate): ?>
                        <li>
                            <a href="/admin/ticket/<?= (int) $candidate->id() ?>"><?= e($candidate->number()) ?></a>
                            <span class="muted small block">
                                <?= e($candidate->target()) ?> · <?= e($candidate->statusLabel()) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Recent tickets</h2>
            <ul class="plain-list">
                <?php foreach (array_slice($recentTickets, 0, 8) as $candidate): ?>
                    <li>
                        <a href="/admin/ticket/<?= (int) $candidate->id() ?>"><?= e($candidate->number()) ?></a>
                        <span class="muted small block"><?= e($candidate->target()) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </aside>
</div>
