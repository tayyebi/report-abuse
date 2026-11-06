<?php
/**
 * @var string $email
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 * @var string $csrf
 */
$error = $errors['email'] ?? null;
$next = $next ?? '';
$value = (string) ($old['email'] ?? $email);
?>
<section class="card narrow auth-card">
    <h1>Staff sign in</h1>
    <p class="lead">
        There is no password to remember. Enter the e-mail address of an administrator and
        we will send a one-time sign-in code to that inbox.
    </p>

    <form method="post" action="/admin/login" class="stack" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php if (!empty($next)): ?>
            <input type="hidden" name="next" value="<?= e((string) $next) ?>">
        <?php endif; ?>
        <div class="field">
            <label for="email">Administrator e-mail</label>
            <input id="email" name="email" type="email" required autofocus
                   value="<?= e($value) ?>" placeholder="you@example.org"
                   class="<?= $error !== null ? 'is-invalid' : '' ?>">
            <?php if ($error !== null): ?>
                <p class="field-error"><?= e($error) ?></p>
            <?php endif; ?>
        </div>
        <button class="btn btn-primary btn-block" type="submit">E-mail me a sign-in code</button>
    </form>

    <p class="muted small">
        Codes expire after <?= (int) round(((int) \App\Core\Config::get('app.auth.code_ttl', 600)) / 60) ?> minutes,
        can be used once, and are stored only as a one-way hash.
    </p>
</section>
