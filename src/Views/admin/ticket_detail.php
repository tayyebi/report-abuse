<?php
/**
 * @var \App\Models\Ticket $ticket
 * @var list<\App\Models\AbuseContact> $contacts
 * @var ?\App\Models\AbuseContact $selectedContact
 * @var list<\App\Models\Evidence> $evidence
 * @var list<\App\Models\TicketEvent> $events
 * @var list<\App\Models\OutboundReport> $reports
 * @var list<\App\Models\InboundMessage> $inbound
 * @var array<string, mixed> $enrichment
 * @var ?string $enrichError
 * @var array<string, string> $statuses
 * @var list<string> $formats
 * @var string $defaultFormat
 * @var string $recipient
 * @var string $csrf
 */
$dns = (array) ($enrichment['dns'] ?? []);
$whois = (array) ($enrichment['whois'] ?? []);
$rdapDomain = (array) ($enrichment['rdap_domain'] ?? []);
$rdapIp = (array) ($enrichment['rdap_ip'] ?? []);
$http = (array) ($enrichment['http'] ?? []);
$canApprove = $ticket->status() === \App\Models\Ticket::STATUS_NEW;
$canSend = in_array($ticket->status(), [\App\Models\Ticket::STATUS_NEW, \App\Models\Ticket::STATUS_APPROVED, \App\Models\Ticket::STATUS_REPORTED], true);
?>
<nav class="breadcrumb">
    <a href="/admin/tickets">Tickets</a> <span>/</span> <span><?= e($ticket->number()) ?></span>
</nav>

<div class="page-head">
    <div>
        <p class="eyebrow"><?= abuse_icon($ticket->abuseType()) ?> <?= e($ticket->abuseTypeLabel()) ?></p>
        <h1><?= e($ticket->number()) ?></h1>
        <p class="muted break">
            <?= e($ticket->target()) ?>
            · created <?= e(time_ago((string) $ticket->get('created_at'))) ?>
            · source <?= e((string) $ticket->get('source', 'web')) ?>
        </p>
    </div>
    <div class="actions">
        <span class="badge <?= status_class($ticket->status()) ?> badge-lg"><?= e($ticket->statusLabel()) ?></span>
        <form method="post" action="/admin/ticket/<?= (int) $ticket->id() ?>/enrich" class="inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="btn btn-ghost" type="submit" title="Re-run DNS, WHOIS, RDAP and HTTP lookups">Re-enrich</button>
        </form>
        <a class="btn btn-ghost" href="/ticket/<?= e($ticket->token()) ?>" target="_blank" rel="noopener">Reporter view ↗</a>
    </div>
</div>

<?php if ($enrichError !== null): ?>
    <div class="alert alert-error">Enrichment failed: <?= e($enrichError) ?></div>
<?php endif; ?>

<div class="detail-grid">
    <div class="detail-main">
        <section class="card">
            <h2>Reported target</h2>
            <dl class="summary-list">
                <div><dt>URL</dt><dd class="break"><?= e((string) ($ticket->url() ?? '—')) ?></dd></div>
                <div><dt>Domain</dt><dd><?= e((string) ($ticket->domain() ?? '—')) ?></dd></div>
                <div><dt>IP address</dt><dd><?= e((string) ($ticket->ip() ?? '—')) ?></dd></div>
                <div><dt>Abuse type</dt><dd><?= e($ticket->abuseTypeLabel()) ?></dd></div>
                <div><dt>Submitted</dt><dd><?= e((string) $ticket->get('created_at')) ?></dd></div>
                <?php if ($ticket->get('approved_at') !== null): ?>
                    <div><dt>Approved</dt><dd><?= e((string) $ticket->get('approved_at')) ?></dd></div>
                <?php endif; ?>
                <?php if ($ticket->get('reported_at') !== null): ?>
                    <div><dt>Reported</dt><dd><?= e((string) $ticket->get('reported_at')) ?></dd></div>
                <?php endif; ?>
            </dl>

            <h3>Reporter description</h3>
            <p class="pre-line"><?= e((string) $ticket->get('description')) ?></p>
        </section>

        <section class="card" id="enrichment">
            <div class="card-head">
                <h2>Technical enrichment</h2>
                <span class="muted small">
                    <?php if ($ticket->isEnriched()): ?>
                        updated <?= e(time_ago((string) $ticket->get('enrichment_at'))) ?>
                        <?php if (!empty($enrichment['duration_ms'])): ?>
                            · <?= (int) $enrichment['duration_ms'] ?> ms
                        <?php endif; ?>
                    <?php else: ?>
                        not run yet
                    <?php endif; ?>
                </span>
            </div>

            <?php if (!empty($enrichment['errors'])): ?>
                <p class="field-error small"><?= e(implode(' · ', array_slice((array) $enrichment['errors'], 0, 4))) ?></p>
            <?php endif; ?>

            <div class="grid-2">
                <div>
                    <h3>DNS</h3>
                    <?php if ($dns === []): ?>
                        <p class="muted small">No DNS data.</p>
                    <?php else: ?>
                        <dl class="summary-list compact">
                            <?php foreach (['a' => 'A', 'aaaa' => 'AAAA', 'mx' => 'MX', 'ns' => 'NS', 'cname' => 'CNAME'] as $key => $label): ?>
                                <?php if (!empty($dns[$key])): ?>
                                    <div>
                                        <dt><?= e($label) ?></dt>
                                        <dd class="mono break"><?= e(implode(', ', array_slice((array) $dns[$key], 0, 6))) ?></dd>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!empty($dns['txt'])): ?>
                                <div><dt>TXT</dt><dd class="mono break small"><?= e(implode(' · ', array_slice((array) $dns['txt'], 0, 4))) ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    <?php endif; ?>

                    <h3>HTTP probe</h3>
                    <?php if ($http === []): ?>
                        <p class="muted small">No probe data.</p>
                    <?php elseif (!empty($http['skipped'])): ?>
                        <p class="muted small">Skipped: <?= e((string) $http['skipped']) ?></p>
                    <?php else: ?>
                        <dl class="summary-list compact">
                            <?php if (!empty($http['status'])): ?>
                                <div><dt>Status</dt><dd><span class="badge badge-other"><?= e((string) $http['status']) ?></span></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($http['server'])): ?>
                                <div><dt>Server</dt><dd class="mono"><?= e((string) $http['server']) ?></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($http['title'])): ?>
                                <div><dt>Title</dt><dd class="break"><?= e((string) $http['title']) ?></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($http['final_url'])): ?>
                                <div><dt>Final URL</dt><dd class="mono break small"><?= e((string) $http['final_url']) ?></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($http['error'])): ?>
                                <div><dt>Error</dt><dd class="field-error"><?= e((string) $http['error']) ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    <?php endif; ?>
                </div>

                <div>
                    <h3>Registration</h3>
                    <dl class="summary-list compact">
                        <?php if (!empty($whois['registrar'])): ?>
                            <div><dt>Registrar</dt><dd><?= e((string) $whois['registrar']) ?></dd></div>
                        <?php elseif (!empty($rdapDomain['registrar'])): ?>
                            <div><dt>Registrar</dt><dd><?= e((string) $rdapDomain['registrar']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($whois['created'])): ?>
                            <div><dt>Created</dt><dd><?= e((string) $whois['created']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($rdapDomain['status'])): ?>
                            <div><dt>Status</dt><dd class="small"><?= e(implode(', ', array_slice((array) $rdapDomain['status'], 0, 4))) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($rdapIp['network'])): ?>
                            <div><dt>Network</dt><dd><?= e((string) $rdapIp['network']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($rdapIp['country'])): ?>
                            <div><dt>Country</dt><dd><?= e((string) $rdapIp['country']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($rdapIp['range'])): ?>
                            <div><dt>Range</dt><dd class="mono small"><?= e((string) $rdapIp['range']) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <?php if (empty($whois['found']) && empty($rdapDomain) && empty($rdapIp)): ?>
                        <p class="muted small">
                            No registration data. <?= e((string) ($whois['reason'] ?? 'WHOIS and RDAP both returned nothing.')) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($whois['raw'])): ?>
                        <details class="details">
                            <summary>Raw WHOIS</summary>
                            <pre class="code-block small"><?= e(str_limit((string) $whois['raw'], 6000, "\n…")) ?></pre>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="card" id="evidence">
            <div class="card-head">
                <h2>Evidence</h2>
                <span class="muted small"><?= count($evidence) ?> file(s)</span>
            </div>

            <?php if ($evidence === []): ?>
                <p class="muted">No files attached.</p>
            <?php else: ?>
                <ul class="evidence-grid">
                    <?php foreach ($evidence as $file): ?>
                        <li>
                            <div class="evidence-icon"><?= $file->isImage() ? '🖼️' : ($file->get('filetype') === 'eml' ? '📧' : '📄') ?></div>
                            <div class="evidence-meta">
                                <a href="/admin/ticket/<?= (int) $ticket->id() ?>/evidence/<?= (int) $file->id() ?>">
                                    <?= e($file->originalName()) ?>
                                </a>
                                <span class="muted small">
                                    <?= e(format_bytes($file->size())) ?>
                                    · <?= e((string) $file->get('filetype')) ?>
                                    · by <?= e((string) $file->get('uploaded_by')) ?>
                                </span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="/admin/ticket/<?= (int) $ticket->id() ?>/evidence" enctype="multipart/form-data" class="inline-upload">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="file" name="evidence[]" multiple required>
                <button class="btn btn-sm" type="submit">Attach</button>
            </form>
        </section>

        <section class="card" id="notes">
            <h2>Internal notes</h2>
            <form method="post" action="/admin/ticket/<?= (int) $ticket->id() ?>/notes" class="stack-sm">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <textarea name="admin_notes" rows="4" placeholder="Only visible to staff"><?= e((string) $ticket->get('admin_notes')) ?></textarea>
                <div><button class="btn btn-sm" type="submit">Save notes</button></div>
            </form>
        </section>

        <section class="card" id="timeline">
            <h2>Timeline</h2>
            <ol class="event-list">
                <?php foreach (array_reverse($events) as $event): ?>
                    <li class="event event-<?= e($event->type()) ?>">
                        <span class="event-dot"></span>
                        <div>
                            <p><?= e($event->summary()) ?></p>
                            <span class="muted small">
                                <?= e($event->actor()) ?><?= $event->get('actor_email') ? ' (' . e((string) $event->get('actor_email')) . ')' : '' ?>
                                · <?= e((string) $event->createdAt()) ?>
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if ($events === []): ?>
                    <li class="muted">No events recorded.</li>
                <?php endif; ?>
            </ol>
        </section>
    </div>

    <aside class="detail-side">
        <section class="card" id="contacts">
            <div class="card-head">
                <h2>Abuse contacts</h2>
                <span class="muted small"><?= count($contacts) ?> found</span>
            </div>

            <?php if ($contacts === []): ?>
                <p class="muted small">No contact discovered yet. Re-run enrichment or add one manually below.</p>
            <?php else: ?>
                <ul class="contact-list">
                    <?php foreach ($contacts as $contact): ?>
                        <li class="<?= $contact->isSelected() ? 'is-selected' : '' ?>">
                            <div class="contact-main">
                                <strong class="mono break"><?= e($contact->email()) ?></strong>
                                <span class="muted small"><?= e($contact->provider()) ?> · source: <?= e($contact->source()) ?></span>
                            </div>
                            <div class="contact-actions">
                                <?php if (!$contact->isSelected()): ?>
                                    <form method="post" action="/admin/ticket/<?= (int) $ticket->id() ?>/contacts/<?= (int) $contact->id() ?>/select" class="inline">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <button class="btn btn-sm btn-ghost" type="submit">Use</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-approved">selected</span>
                                <?php endif; ?>
                                <form method="post" action="/admin/ticket/<?= (int) $ticket->id() ?>/contacts/<?= (int) $contact->id() ?>/delete" class="inline"
                                      data-confirm="Remove this contact?">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button class="btn btn-sm btn-danger-ghost" type="submit">✕</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="/admin/ticket/<?= (int) $ticket->id() ?>/contacts" class="stack-sm add-contact">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="email" name="abuse_email" placeholder="abuse@provider.example" required>
                <input type="text" name="provider_name" placeholder="Provider name (optional)">
                <button class="btn btn-sm" type="submit">Add contact</button>
            </form>
        </section>

        <section class="card" id="send">
            <h2>Send report</h2>

            <?php if ($canApprove): ?>
                <form method="post" action="/admin/ticket/<?= (int) $ticket->id() ?>/approve" class="stack-sm">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <p class="muted small">Approve the ticket with the selected recipient to generate a draft.</p>
                    <select name="contact_id" required>
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?= (int) $contact->id() ?>"<?= selected_if($contact->isSelected()) ?>>
                                <?= e($contact->email()) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary btn-block" type="submit"<?= $contacts === [] ? ' disabled' : '' ?>>
                        Approve &amp; preview draft
                    </button>
                    <?php if ($contacts === []): ?>
                        <p class="muted small">Add an abuse contact first.</p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if ($canSend): ?>
                <form method="get" action="/admin/ticket/<?= (int) $ticket->id() ?>/preview" class="stack-sm">
                    <label class="small muted" for="recipient">Recipient</label>
                    <input id="recipient" type="email" name="recipient" value="<?= e($recipient) ?>" placeholder="abuse@provider.example">
                    <label class="small muted" for="format">Format</label>
                    <select id="format" name="format">
                        <?php foreach ($formats as $format): ?>
                            <option value="<?= e($format) ?>"<?= selected_if($defaultFormat === $format) ?>>
                                <?= e(strtoupper($format)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-block" type="submit">Open preview</button>
                </form>
            <?php endif; ?>

            <?php if (!$canSend && !$canApprove): ?>
                <p class="muted small">This ticket is <?= e(strtolower($ticket->statusLabel())) ?>; reopen it to send another report.</p>
            <?php endif; ?>
        </section>

        <section class="card" id="reports">
            <h2>Sent reports</h2>
            <?php if ($reports === []): ?>
                <p class="muted small">Nothing sent yet.</p>
            <?php else: ?>
                <ul class="plain-list">
                    <?php foreach ($reports as $report): ?>
                        <li>
                            <strong class="mono break"><?= e($report->recipient()) ?></strong>
                            <span class="small">
                                <?= e(strtoupper((string) $report->get('format'))) ?>
                                · <span class="badge <?= $report->isSent() ? 'badge-reported' : 'badge-rejected' ?>"><?= e((string) $report->get('send_status')) ?></span>
                            </span>
                            <span class="muted small block">
                                <?= e((string) ($report->get('sent_at') ?? $report->get('created_at'))) ?>
                                · <a href="/admin/ticket/<?= (int) $ticket->id() ?>/report/<?= (int) $report->id() ?>">download .eml</a>
                            </span>
                            <?php if ($report->get('error_message')): ?>
                                <span class="field-error small block"><?= e((string) $report->get('error_message')) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card" id="responses">
            <h2>Provider replies</h2>
            <?php if ($inbound === []): ?>
                <p class="muted small">No messages linked to this ticket yet.</p>
            <?php else: ?>
                <?php foreach ($inbound as $message): ?>
                    <article class="message-card">
                        <header>
                            <strong class="mono small break"><?= e($message->from()) ?></strong>
                            <span class="muted small"><?= e((string) $message->get('received_at')) ?></span>
                        </header>
                        <p class="message-subject"><?= e($message->subject()) ?></p>
                        <pre class="message-body"><?= e(str_limit($message->bodyText(), 1200, '…')) ?></pre>
                        <a class="link small" href="/admin/inbox/<?= (int) $message->id() ?>">Open message</a>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="card" id="status">
            <h2>Change status</h2>
            <form method="post" action="/admin/ticket/<?= (int) $ticket->id() ?>/status" class="stack-sm">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <textarea name="note" rows="3" placeholder="Resolution note (sent to the reporter when resolving)"></textarea>
                <div class="button-row">
                    <button class="btn btn-sm" name="action" value="resolve">Resolve</button>
                    <button class="btn btn-sm btn-ghost" name="action" value="close">Close</button>
                    <button class="btn btn-sm btn-ghost" name="action" value="reopen">Reopen</button>
                    <button class="btn btn-sm btn-danger-ghost" name="action" value="reject" data-confirm="Reject this ticket?">Reject</button>
                </div>
            </form>
        </section>
    </aside>
</div>
