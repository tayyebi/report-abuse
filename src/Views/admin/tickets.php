<?php
/**
 * @var list<\App\Models\Ticket> $tickets
 * @var array<string, string> $filters
 * @var array<string, int> $counts
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array<string, string> $statuses
 * @var array<string, string> $abuseTypes
 * @var string $csrf
 */
$query = static function (array $overrides) use ($filters, $page): string {
    $params = array_merge([
        'status' => $filters['status'],
        'abuse_type' => $filters['abuse_type'],
        'source' => $filters['source'],
        'q' => $filters['q'],
        'page' => $page,
    ], $overrides);

    return '/admin/tickets?' . http_build_query(array_filter($params, static fn ($v) => $v !== '' && $v !== null));
};
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Queue</p>
        <h1>Tickets</h1>
        <p class="muted"><?= (int) $total ?> ticket(s) match the current filters</p>
    </div>
</div>

<section class="card">
    <form class="filters" method="get" action="/admin/tickets">
        <div class="field">
            <label for="q">Search</label>
            <input id="q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Ticket, domain, reporter, IP">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="all"<?= selected_if($filters['status'] === 'all') ?>>All statuses</option>
                <option value="open"<?= selected_if($filters['status'] === 'open') ?>>Open (new, approved, reported)</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= selected_if($filters['status'] === $key) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="abuse_type">Abuse type</label>
            <select id="abuse_type" name="abuse_type">
                <option value="all"<?= selected_if($filters['abuse_type'] === 'all') ?>>All types</option>
                <?php foreach ($abuseTypes as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= selected_if($filters['abuse_type'] === $key) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="source">Channel</label>
            <select id="source" name="source">
                <option value="all"<?= selected_if($filters['source'] === 'all') ?>>All channels</option>
                <option value="web"<?= selected_if($filters['source'] === 'web') ?>>Web form</option>
                <option value="email"<?= selected_if($filters['source'] === 'email') ?>>E-mail</option>
            </select>
        </div>
        <div class="field field-actions">
            <button class="btn btn-primary" type="submit">Apply</button>
            <a class="btn btn-ghost" href="/admin/tickets">Reset</a>
        </div>
    </form>
</section>

<section class="card">
    <?php if ($tickets === []): ?>
        <p class="muted">No tickets match these filters.</p>
    <?php else: ?>
        <table class="table table-hover">
            <thead>
            <tr>
                <th>Ticket</th>
                <th>Type</th>
                <th>Target</th>
                <th>Reporter</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tickets as $ticket): ?>
                <tr onclick="window.location='/admin/ticket/<?= (int) $ticket->id() ?>'" class="clickable">
                    <td>
                        <a href="/admin/ticket/<?= (int) $ticket->id() ?>"><?= e($ticket->number()) ?></a>
                        <?php if ($ticket->get('source') === 'email'): ?>
                            <span class="chip">e-mail</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="icon"><?= abuse_icon($ticket->abuseType()) ?></span> <?= e($ticket->abuseTypeLabel()) ?></td>
                    <td class="break"><?= e($ticket->target()) ?></td>
                    <td class="break small"><?= e((string) $ticket->get('reporter_email')) ?></td>
                    <td><span class="badge <?= status_class($ticket->status()) ?>"><?= e($ticket->statusLabel()) ?></span></td>
                    <td class="muted small"><?= e(time_ago((string) $ticket->get('created_at'))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($pages > 1): ?>
            <nav class="pagination">
                <?php if ($page > 1): ?>
                    <a class="btn btn-sm btn-ghost" href="<?= e($query(['page' => $page - 1])) ?>">← Previous</a>
                <?php endif; ?>
                <span class="muted small">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-sm btn-ghost" href="<?= e($query(['page' => $page + 1])) ?>">Next →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
