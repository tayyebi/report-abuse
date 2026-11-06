<section class="card narrow center">
    <h1>404</h1>
    <p class="lead">We could not find that page or report.</p>
    <p class="muted">If you followed a ticket link, check that it was copied completely.</p>
    <div class="actions center">
        <a class="btn" href="/">Report abuse</a>
        <?php if (!empty($isAdmin)): ?>
            <a class="btn btn-ghost" href="/admin">Back to dashboard</a>
        <?php endif; ?>
    </div>
</section>
