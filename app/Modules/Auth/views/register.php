<section class="ec-auth">
    <div class="ec-auth-card">
        <h1 class="h3 mb-1"><?= e(t('auth.register.title')) ?></h1>
        <p class="text-body-secondary mb-4"><?= e(t('auth.register.lead')) ?></p>
        <form data-ec-form data-endpoint="/api/v1/auth/register" data-reset="1" novalidate>
            <div class="mb-3">
                <label class="form-label" for="reg-name"><?= e(t('auth.field.display_name')) ?></label>
                <input class="form-control" id="reg-name" name="display_name" type="text" autocomplete="name" required minlength="2" maxlength="100" autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="reg-email"><?= e(t('auth.field.email')) ?></label>
                <input class="form-control" id="reg-email" name="email" type="email" autocomplete="email" required maxlength="254">
            </div>
            <div class="mb-3">
                <label class="form-label" for="reg-password"><?= e(t('auth.field.password')) ?></label>
                <input class="form-control" id="reg-password" name="password" type="password" autocomplete="new-password"
                       required minlength="12" maxlength="128" aria-describedby="reg-password-help">
                <div id="reg-password-help" class="form-text"><?= e(t('auth.password.help')) ?></div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="reg-password2"><?= e(t('auth.field.password_confirm')) ?></label>
                <input class="form-control" id="reg-password2" name="password_confirm" type="password" autocomplete="new-password"
                       required data-ec-local data-ec-match="password">
            </div>
            <button class="btn btn-primary w-100" type="submit"><?= e(t('auth.register.submit')) ?></button>
        </form>
        <p class="mt-4 mb-0 text-center small">
            <?= e(t('auth.register.have_account')) ?> <a href="<?= e(url('/login')) ?>"><?= e(t('nav.login')) ?></a>
        </p>
    </div>
</section>
