<?php
/**
 * @var string      $next
 * @var string|null $notice
 */
$notices = ['reset' => t('auth.notice.reset'), 'verified' => t('auth.notice.verified'), 'logout' => t('auth.notice.logout')];
?>
<section class="ec-auth">
    <div class="ec-auth-card">
        <h1 class="h3 mb-1"><?= e(t('auth.login.title')) ?></h1>
        <p class="text-body-secondary mb-4"><?= e(t('auth.login.lead')) ?></p>
<?php if ($notice !== null && isset($notices[$notice])): ?>
        <div class="alert alert-success" role="status"><?= e($notices[$notice]) ?></div>
<?php endif; ?>
        <form data-ec-form data-endpoint="/api/v1/auth/login" data-redirect="<?= e($next) ?>" novalidate>
            <div class="mb-3">
                <label class="form-label" for="login-email"><?= e(t('auth.field.email')) ?></label>
                <input class="form-control" id="login-email" name="email" type="email" autocomplete="username" required maxlength="254" autofocus>
            </div>
            <div class="mb-2">
                <label class="form-label" for="login-password"><?= e(t('auth.field.password')) ?></label>
                <input class="form-control" id="login-password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
            </div>
            <p class="mb-4 small"><a href="<?= e(url('/forgot-password')) ?>"><?= e(t('auth.login.forgot')) ?></a></p>
            <button class="btn btn-primary w-100" type="submit"><?= e(t('auth.login.submit')) ?></button>
        </form>
        <p class="mt-4 mb-0 text-center small">
            <?= e(t('auth.login.no_account')) ?> <a href="<?= e(url('/register')) ?>"><?= e(t('nav.register')) ?></a>
        </p>
    </div>
</section>
