<?php
/**
 * @var string $email
 * @var array<string, string> $errors
 * @var string $csrf
 */
?>
<section class="card narrow auth-card">
    <h1>Enter your code</h1>
    <p class="lead">
        We sent a one-time code to <strong><?= e($email) ?></strong>.
        Enter it below to continue.
    </p>

    <form method="post" action="/admin/login/verify" class="stack" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php if (!empty($next)): ?>
            <input type="hidden" name="next" value="<?= e((string) $next) ?>">
        <?php endif; ?>
        <div class="field">
            <label for="code">Sign-in code</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                   maxlength="10" required autofocus class="code-input" placeholder="••••••">
            <?php if (isset($errors['code'])): ?>
                <p class="field-error"><?= e($errors['code']) ?></p>
            <?php endif; ?>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Verify and sign in</button>
    </form>

    <div class="actions between small">
        <a href="/admin/login">Use another e-mail address</a>
        <span class="muted">Wrong or expired code? Request a new one.</span>
    </div>
</section>
