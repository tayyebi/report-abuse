<?php
/**
 * @var \App\Models\Ticket $ticket
 * @var list<\App\Models\AbuseContact> $contacts
 * @var string $recipient
 * @var string $format
 * @var list<string> $formats
 * @var array{format: string, subject: string, mime: string, human_text: string, message_id: string} $composed
 * @var string $rawMime
 * @var string $humanText
 * @var string $csrf
 */
?>
<nav class="breadcrumb">
    <a href="/admin/tickets">Tickets</a> <span>/</span>
    <a href="/admin/ticket/<?= (int) $ticket->id() ?>"><?= e($ticket->number()) ?></a> <span>/</span>
    <span>Preview</span>
</nav>

<div class="page-head">
    <div>
        <p class="eyebrow">Draft report · <?= e(strtoupper($format)) ?></p>
        <h1><?= e($ticket->number()) ?></h1>
        <p class="muted break">To: <?= e($recipient !== '' ? $recipient : '(no recipient)') ?> · Subject: <?= e($composed['subject']) ?></p>
    </div>
    <div class="actions">
        <form method="get" action="/admin/ticket/<?= (int) $ticket->id() ?>/preview" class="inline">
            <select name="format" onchange="this.form.submit()" aria-label="Report format">
                <?php foreach ($formats as $option): ?>
                    <option value="<?= e($option) ?>"<?= selected_if($format === $option) ?>><?= e(strtoupper($option)) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="recipient" value="<?= e($recipient) ?>">
            <noscript><button class="btn btn-sm" type="submit">Switch</button></noscript>
        </form>
        <a class="btn btn-ghost" href="/admin/ticket/<?= (int) $ticket->id() ?>">Cancel</a>
    </div>
</div>

<div class="preview-grid">
    <section class="card">
        <div class="tabs" data-tabs>
            <button type="button" class="tab is-active" data-tab="human">Message</button>
            <button type="button" class="tab" data-tab="raw">Raw MIME</button>
            <button type="button" class="tab" data-tab="feedback">Feedback report</button>
        </div>

        <form method="post" action="/admin/ticket/<?= (int) $ticket->id() ?>/send" class="stack" id="send-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="format" value="<?= e($format) ?>">

            <div class="tab-panel is-active" data-panel="human">
                <div class="grid-2">
                    <div class="field">
                        <label for="recipient_email">Recipient</label>
                        <input id="recipient_email" name="recipient_email" type="email" required value="<?= e($recipient) ?>"
                               list="contact-suggestions">
                        <datalist id="contact-suggestions">
                            <?php foreach ($contacts as $contact): ?>
                                <option value="<?= e($contact->email()) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="field">
                        <label for="subject">Subject</label>
                        <input id="subject" name="subject" type="text" value="<?= e($composed['subject']) ?>" required>
                    </div>
                </div>

                <div class="field">
                    <label for="body">Human readable part <span class="muted">(editable)</span></label>
                    <textarea id="body" name="body" rows="26" class="mono small"><?= e($humanText) ?></textarea>
                    <p class="field-hint">
                        The MIME envelope (ARF feedback-report or X-ARF JSON) and the evidence parts are
                        generated automatically. Your edits apply to the plain-text part.
                    </p>
                </div>
            </div>

            <div class="tab-panel" data-panel="raw">
                <p class="muted small">Exactly what will be handed to the SMTP server (headers, boundaries, attachments):</p>
                <pre class="code-block small tall"><?= e($rawMime) ?></pre>
            </div>

            <div class="tab-panel" data-panel="feedback">
                <?php if ($format === 'arf'): ?>
                    <p class="muted small">RFC 5965 <code>message/feedback-report</code> fields included in this message:</p>
                    <?php
                    $feedback = '';
                    if (preg_match('/Content-Type: message\/feedback-report\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--/s', $rawMime, $m)) {
                        $feedback = trim($m[1]);
                    }
                    ?>
                    <pre class="code-block small"><?= e($feedback !== '' ? $feedback : 'Feedback report part not found.') ?></pre>
                <?php else: ?>
                    <p class="muted small">The X-ARF document is embedded as an <code>application/json</code> MIME part. It is generated from the ticket data and cannot be edited here.</p>
                <?php endif; ?>
            </div>

            <div class="actions between">
                <p class="muted small">
                    Sending marks the ticket as <strong>reported</strong>, stores the outbound message and e-mails the reporter.
                </p>
                <button class="btn btn-primary btn-lg" type="submit" data-confirm="Send this report to the abuse desk?">
                    Send report
                </button>
            </div>
        </form>
    </section>

    <aside class="card preview-side">
        <h2>Checklist</h2>
        <ul class="checklist">
            <li class="<?= $ticket->target() !== 'unknown target' ? 'ok' : 'warn' ?>">Target identified: <?= e($ticket->target()) ?></li>
            <li class="<?= $ticket->url() !== null ? 'ok' : 'warn' ?>">URL recorded<?= $ticket->url() !== null ? ' (evidence archived)' : ' — none provided' ?></li>
            <li class="<?= $ticket->evidence() !== [] ? 'ok' : 'warn' ?>"><?= count($ticket->evidence()) ?> evidence file(s) attached</li>
            <li class="<?= $ticket->isEnriched() ? 'ok' : 'warn' ?>">Enrichment <?= $ticket->isEnriched() ? 'complete' : 'missing' ?></li>
            <li class="<?= count($contacts) > 0 ? 'ok' : 'warn' ?>"><?= count($contacts) ?> abuse contact(s) available</li>
        </ul>

        <h3>Format</h3>
        <p class="small muted">
            <?php if ($format === 'arf'): ?>
                ARF (RFC 5965) is the interoperable baseline: a human part, a
                <code>message/feedback-report</code> part and evidence.
            <?php elseif ($format === 'xarf'): ?>
                X-ARF wraps the report in JSON — preferred by some CERTs and automated pipelines.
            <?php else: ?>
                Plain text: use when the receiving desk explicitly asks for a simple e-mail.
            <?php endif; ?>
        </p>

        <h3>Tracking</h3>
        <dl class="summary-list compact">
            <div><dt>Message-ID</dt><dd class="mono small break"><?= e($composed['message_id']) ?></dd></div>
            <div><dt>Ticket</dt><dd><?= e($ticket->number()) ?></dd></div>
        </dl>
    </aside>
</div>
