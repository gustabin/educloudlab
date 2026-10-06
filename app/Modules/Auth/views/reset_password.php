<?php /** @var string $token */ ?>
<section class="ec-auth">
    <div class="ec-auth-card">
        <h1 class="h3 mb-1"><?= e(t('auth.reset.title')) ?></h1>
<?php if ($token !== ''): ?>
        <p class="text-body-secondary mb-4"><?= e(t('auth.reset.lead')) ?></p>
        <form data-ec-form data-endpoint="/api/v1/auth/password/reset" data-redirect="/login?notice=reset" novalidate>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="mb-3">
                <label class="form-label" for="reset-password"><?= e(t('auth.field.new_password')) ?></label>
                <input class="form-control" id="reset-password" name="password" type="password" autocomplete="new-password"
                       required minlength="12" maxlength="128" aria-describedby="reset-password-help" autofocus>
                <div id="reset-password-help" class="form-text"><?= e(t('auth.password.help')) ?></div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="reset-password2"><?= e(t('auth.field.password_confirm')) ?></label>
                <input class="form-control" id="reset-password2" name="password_confirm" type="password" autocomplete="new-password"
                       required data-ec-local data-ec-match="password">
            </div>
            <button class="btn btn-primary w-100" type="submit"><?= e(t('auth.reset.submit')) ?></button>
        </form>
<?php else: ?>
        <div class="alert alert-warning" role="alert"><?= e(t('auth.reset.invalid')) ?></div>
        <a class="btn btn-primary w-100" href="<?= e(url('/forgot-password')) ?>"><?= e(t('auth.forgot.submit')) ?></a>
<?php endif; ?>
    </div>
</section>
