<?php
/**
 * Base layout.
 *
 * @var string $content
 * @var string|null $title
 * @var bool $isAdmin
 * @var string $appName
 * @var string|null $adminEmail
 * @var string $csrf
 */

use App\Core\Session;

$flashes = Session::takeFlashes();
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName;
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$navItems = [
    ['/admin', 'Dashboard', $path === '/admin'],
    ['/admin/tickets', 'Tickets', str_starts_with($path, '/admin/ticket')],
    ['/admin/inbox', 'Inbox', str_starts_with($path, '/admin/inbox')],
    ['/admin/settings', 'Settings', str_starts_with($path, '/admin/settings')],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
</head>
<body class="<?= !empty($isAdmin) ? 'is-admin' : 'is-public' ?>">
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= !empty($isAdmin) ? '/admin' : '/' ?>">
            <span class="brand-mark">🛡️</span>
            <span class="brand-text"><?= e($appName) ?></span>
        </a>

        <?php if (!empty($isAdmin)): ?>
            <nav class="nav">
                <?php foreach ($navItems as [$href, $label, $active]): ?>
                    <a href="<?= e($href) ?>" class="nav-link<?= $active ? ' is-active' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="topbar-user">
                <span class="user-email" title="Signed in as"><?= e((string) ($adminEmail ?? '')) ?></span>
                <form method="post" action="/admin/logout" class="inline">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">Sign out</button>
                </form>
            </div>
        <?php else: ?>
            <div class="topbar-user">
                <a class="btn btn-ghost btn-sm" href="/">Report abuse</a>
                <a class="btn btn-sm" href="/admin">Staff sign in</a>
            </div>
        <?php endif; ?>
    </div>
</header>

<main class="container">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status">
            <?= e($flash['message']) ?>
        </div>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer class="footer">
    <div class="footer-inner">
        <span><?= e($appName) ?> · passwordless staff access</span>
        <span class="muted">Built with plain PHP &amp; PostgreSQL — no cron, no queue, no framework.</span>
    </div>
</footer>

<script src="/assets/app.js" defer></script>
</body>
</html>
