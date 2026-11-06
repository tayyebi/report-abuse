<?php
/**
 * @var list<\App\Models\Admin> $admins
 * @var ?int $currentAdminId
 * @var array<string, mixed> $smtp
 * @var array<string, mixed> $imap
 * @var array<string, mixed> $piggyback
 * @var array<string, mixed> $system
 * @var array{intake: int, responses: int, unmatched: int} $inboxStats
 * @var string $csrf
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Configuration</p>
        <h1>Settings</h1>
        <p class="muted">Everything here is driven by environment variables — see <code>.env.example</code>.</p>
    </div>
</div>

<div class="grid-2">
    <section class="card" id="admins">
        <div class="card-head">
            <h2>Administrators</h2>
            <span class="muted small"><?= count($admins) ?> account(s)</span>
        </div>
        <p class="muted small">
            Accounts are only an e-mail address. There is no password and no username stored anywhere;
            signing in always requires a one-time code sent to the address.
        </p>

        <table class="table">
            <thead><tr><th>E-mail</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($admins as $admin): ?>
                <tr>
                    <td class="mono small break">
                        <?= e($admin->email()) ?>
                        <?php if ((int) $admin->id() === (int) $currentAdminId): ?><span class="chip">you</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($admin->isActive()): ?>
                            <span class="badge badge-resolved">active</span>
                        <?php else: ?>
                            <span class="badge badge-other">disabled</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted small"><?= e(time_ago((string) $admin->get('last_login_at'))) ?></td>
                    <td>
                        <?php if ((int) $admin->id() !== (int) $currentAdminId): ?>
                            <form method="post" action="/admin/settings/admins/<?= (int) $admin->id() ?>/toggle" class="inline">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="active" value="<?= $admin->isActive() ? '0' : '1' ?>">
                                <button class="btn btn-sm btn-ghost" type="submit"><?= $admin->isActive() ? 'Disable' : 'Enable' ?></button>
                            </form>
                        <?php else: ?>
                            <span class="muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="/admin/settings/admins" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="email" name="email" placeholder="new-admin@example.org" required>
            <button class="btn btn-sm" type="submit">Add administrator</button>
        </form>
    </section>

    <section class="card" id="mail">
        <div class="card-head">
            <h2>Mail</h2>
            <span class="badge <?= $smtp['configured'] ? 'badge-resolved' : 'badge-other' ?>">
                <?= $smtp['configured'] ? 'SMTP configured' : 'log transport' ?>
            </span>
        </div>

        <dl class="summary-list compact">
            <div><dt>Transport</dt><dd><?= e((string) $smtp['transport']) ?></dd></div>
            <div><dt>From</dt><dd class="mono small break"><?= e((string) $smtp['from']) ?></dd></div>
            <div><dt>SMTP host</dt><dd class="mono small"><?= e((string) $smtp['host'] ?: '—') ?>:<?= e((string) $smtp['port']) ?></dd></div>
            <div><dt>Encryption</dt><dd><?= e((string) $smtp['encryption']) ?></dd></div>
            <div><dt>Username</dt><dd class="mono small break"><?= e((string) $smtp['username'] ?: '—') ?></dd></div>
        </dl>

        <h3>IMAP</h3>
        <dl class="summary-list compact">
            <div><dt>Host</dt><dd class="mono small"><?= e((string) $imap['host'] ?: 'not configured') ?>:<?= e((string) $imap['port']) ?></dd></div>
            <div><dt>Encryption</dt><dd><?= e((string) $imap['encryption']) ?></dd></div>
            <div><dt>Intake mailbox</dt><dd class="mono small"><?= e((string) $imap['intake_mailbox']) ?></dd></div>
            <div><dt>Response mailbox</dt><dd class="mono small"><?= e((string) $imap['response_mailbox']) ?></dd></div>
        </dl>

        <div class="button-row">
            <form method="post" action="/admin/settings/mail-test" class="inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn-sm" type="submit">Send test e-mail</button>
            </form>
            <form method="post" action="/admin/settings/imap-check" class="inline">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn-sm btn-ghost" type="submit"<?= $imap['configured'] ? '' : ' disabled' ?>>Check mailboxes now</button>
            </form>
        </div>
        <p class="muted small">
            Inbox totals: <?= (int) $inboxStats['responses'] ?> responses, <?= (int) $inboxStats['intake'] ?> intake,
            <?= (int) $inboxStats['unmatched'] ?> unmatched.
        </p>
    </section>

    <section class="card">
        <h2>Piggyback processing</h2>
        <p class="muted small">
            No cron, no workers. Mailbox polling and enrichment run inside admin requests when their
            interval has elapsed.
        </p>
        <dl class="summary-list compact">
            <div><dt>Last intake check</dt><dd><?= e(time_ago((string) ($piggyback['last_intake_check'] ?? null))) ?></dd></div>
            <div><dt>Last response check</dt><dd><?= e(time_ago((string) ($piggyback['last_response_check'] ?? null))) ?></dd></div>
            <div><dt>Interval</dt><dd><?= (int) \App\Core\Config::get('app.piggyback.imap_interval', 300) ?> s</dd></div>
            <div><dt>Enrichment TTL</dt><dd><?= (int) \App\Core\Config::get('app.piggyback.enrichment_interval', 900) ?> s</dd></div>
        </dl>
        <?php foreach (['last_intake_report' => 'Intake', 'last_response_report' => 'Responses'] as $key => $label): ?>
            <?php $report = (array) ($piggyback[$key] ?? []); ?>
            <?php if ($report !== []): ?>
                <p class="muted small">
                    <?= e($label) ?> last run: <?= e((string) ($report['at'] ?? 'unknown')) ?>
                    <?= isset($report['fetched']) ? '· ' . (int) $report['fetched'] . ' fetched' : '' ?>
                    <?= isset($report['created']) ? '· ' . (int) $report['created'] . ' new' : '' ?>
                    <?= !empty($report['error']) ? '· error: ' . e(str_limit((string) $report['error'], 120)) : '' ?>
                </p>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>

    <section class="card">
        <h2>System</h2>
        <dl class="summary-list compact">
            <div><dt>PHP</dt><dd><?= e((string) $system['php']) ?></dd></div>
            <div>
                <dt>Extensions</dt>
                <dd class="small">
                    <?php foreach ((array) $system['extensions'] as $name => $loaded): ?>
                        <span class="chip <?= $loaded ? 'chip-ok' : 'chip-warn' ?>"><?= e((string) $name) ?></span>
                    <?php endforeach; ?>
                </dd>
            </div>
            <div>
                <dt>WHOIS</dt>
                <dd class="small">
                    <?php if ($system['shell_exec']): ?>
                        <span class="badge badge-resolved">available</span>
                    <?php else: ?>
                        <span class="badge badge-other">unavailable</span>
                        <span class="muted"><?= e((string) $system['whois_reason']) ?></span>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Uploads</dt>
                <dd class="small mono break">
                    <?= e((string) $system['upload_dir']) ?>
                    <span class="badge <?= $system['upload_writable'] ? 'badge-resolved' : 'badge-rejected' ?>">
                        <?= $system['upload_writable'] ? 'writable' : 'not writable' ?>
                    </span>
                </dd>
            </div>
        </dl>

        <details class="details">
            <summary>System state (<code>system_state</code>)</summary>
            <pre class="code-block small"><?= e((string) json_encode($system['state'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </details>
    </section>
</div>
