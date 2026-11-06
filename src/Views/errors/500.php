<section class="card narrow">
    <h1>Something went wrong</h1>
    <p class="lead"><?= e($message ?? 'An unexpected error occurred.') ?></p>
    <?php if (!empty($trace)): ?>
        <pre class="code-block"><?= e($trace) ?></pre>
    <?php endif; ?>
    <div class="actions">
        <a class="btn" href="/">Home</a>
        <?php if (!empty($isAdmin)): ?>
            <a class="btn btn-ghost" href="/admin">Dashboard</a>
        <?php endif; ?>
    </div>
</section>
