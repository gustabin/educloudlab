<?php /** @var string $token */ ?>
<section class="ec-auth">
    <div class="ec-auth-card">
        <h1 class="h3 mb-1"><?= e(t('auth.verify.title')) ?></h1>
<?php if ($token !== ''): ?>
        <p class="text-body-secondary mb-4"><?= e(t('auth.verify.lead')) ?></p>
        <form data-ec-form data-endpoint="/api/v1/auth/verify-email" data-redirect="/login?notice=verified" novalidate>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="mb-4">
                <label class="form-label" for="verify-password"><?= e(t('auth.field.password')) ?></label>
                <input class="form-control" id="verify-password" name="password" type="password" autocomplete="current-password"
                       required maxlength="1024" aria-describedby="verify-password-help" autofocus>
                <div id="verify-password-help" class="form-text"><?= e(t('auth.verify.password_help')) ?></div>
            </div>
            <button class="btn btn-primary w-100" type="submit"><?= e(t('auth.verify.submit')) ?></button>
        </form>
<?php else: ?>
        <div class="alert alert-warning" role="alert"><?= e(t('auth.verify.invalid')) ?></div>
<?php endif; ?>
        <hr class="my-4">
        <h2 class="h6"><?= e(t('auth.verify.resend_title')) ?></h2>
        <form data-ec-form data-endpoint="/api/v1/auth/verify-email/resend" data-reset="1" novalidate>
            <div class="mb-3">
                <label class="form-label" for="resend-email"><?= e(t('auth.field.email')) ?></label>
                <input class="form-control" id="resend-email" name="email" type="email" autocomplete="email" required maxlength="254">
            </div>
            <button class="btn btn-outline-primary w-100" type="submit"><?= e(t('auth.verify.resend_submit')) ?></button>
        </form>
    </div>
</section>
