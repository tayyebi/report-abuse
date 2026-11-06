<?php
/**
 * @var array<string, string> $abuseTypes
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 * @var int $maxFiles
 * @var string $maxFileSize
 * @var string $csrf
 */
$val = static fn (string $key, string $default = ''): string => (string) ($old[$key] ?? $default);
$err = static fn (string $key): ?string => $errors[$key] ?? null;
?>
<div class="hero">
    <div>
        <h1>Report abuse</h1>
        <p class="lead">
            Report a phishing page, malware distribution, spam run or fraud. A human analyst
            reviews every submission and forwards it to the responsible hosting provider.
        </p>
    </div>
    <div class="hero-points">
        <div class="hero-point"><strong>Private tracking</strong><span>You get a secret status link — no account needed.</span></div>
        <div class="hero-point"><strong>Standards based</strong><span>Reports are delivered as ARF/X-ARF with evidence.</span></div>
        <div class="hero-point"><strong>No data resale</strong><span>Your address is only used for status updates.</span></div>
    </div>
</div>

<section class="card">
    <form method="post" action="/report" enctype="multipart/form-data" class="stack" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="grid-2">
            <div class="field">
                <label for="abuse_type">Kind of abuse</label>
                <select id="abuse_type" name="abuse_type" class="<?= $err('abuse_type') !== null ? 'is-invalid' : '' ?>">
                    <?php foreach ($abuseTypes as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $val('abuse_type', 'phishing') === $key ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($err('abuse_type') !== null): ?><p class="field-error"><?= e((string) $err('abuse_type')) ?></p><?php endif; ?>
            </div>

            <div class="field">
                <label for="target">Reported URL, domain or IP</label>
                <input id="target" name="target" type="text" required
                       value="<?= e($val('target')) ?>" placeholder="https://example.com/login"
                       class="<?= $err('target') !== null ? 'is-invalid' : '' ?>">
                <p class="field-hint">Paste the exact page that hosts the abuse when you can.</p>
                <?php if ($err('target') !== null): ?><p class="field-error"><?= e((string) $err('target')) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label for="description">What did you observe?</label>
            <textarea id="description" name="description" rows="7" required minlength="20"
                      placeholder="Describe what you saw, when it happened and how you found it. Include any account or brand being impersonated."
                      class="<?= $err('description') !== null ? 'is-invalid' : '' ?>"><?= e($val('description')) ?></textarea>
            <p class="field-hint">Minimum 20 characters. Do not include passwords or personal data about third parties.</p>
            <?php if ($err('description') !== null): ?><p class="field-error"><?= e((string) $err('description')) ?></p><?php endif; ?>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="reporter_email">Your e-mail address</label>
                <input id="reporter_email" name="reporter_email" type="email" required
                       value="<?= e($val('reporter_email')) ?>" placeholder="you@example.org"
                       class="<?= $err('reporter_email') !== null ? 'is-invalid' : '' ?>">
                <p class="field-hint">Used to send you the status link and updates.</p>
                <?php if ($err('reporter_email') !== null): ?><p class="field-error"><?= e((string) $err('reporter_email')) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label for="reporter_name">Your name <span class="muted">(optional)</span></label>
                <input id="reporter_name" name="reporter_name" type="text" value="<?= e($val('reporter_name')) ?>"
                       placeholder="Jane Doe">
            </div>
        </div>

        <div class="field">
            <label for="evidence">Evidence <span class="muted">(optional)</span></label>
            <input id="evidence" name="evidence[]" type="file" multiple
                   accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.txt,.eml,.msg,.log,.csv,.json,.har,.zip,.html,.htm">
            <p class="field-hint">
                Screenshots, the full e-mail (.eml) or headers. Up to <?= (int) $maxFiles ?> files,
                <?= e($maxFileSize) ?> each.
            </p>
            <?php if ($err('files') !== null): ?><p class="field-error"><?= e((string) $err('files')) ?></p><?php endif; ?>
            <ul class="file-list" data-file-list></ul>
        </div>

        <div class="honeypot" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <div class="actions between">
            <p class="muted small">
                We will send a confirmation e-mail with your private tracking link.
            </p>
            <button class="btn btn-primary btn-lg" type="submit">Submit report</button>
        </div>
    </form>
</section>
