<section class="ec-auth">
    <div class="ec-auth-card">
        <h1 class="h3 mb-1"><?= e(t('auth.forgot.title')) ?></h1>
        <p class="text-body-secondary mb-4"><?= e(t('auth.forgot.lead')) ?></p>
        <form data-ec-form data-endpoint="/api/v1/auth/password/forgot" data-reset="1" novalidate>
            <div class="mb-4">
                <label class="form-label" for="forgot-email"><?= e(t('auth.field.email')) ?></label>
                <input class="form-control" id="forgot-email" name="email" type="email" autocomplete="email" required maxlength="254" autofocus>
            </div>
            <button class="btn btn-primary w-100" type="submit"><?= e(t('auth.forgot.submit')) ?></button>
        </form>
        <p class="mt-4 mb-0 text-center small"><a href="<?= e(url('/login')) ?>"><?= e(t('auth.back_to_login')) ?></a></p>
    </div>
</section>
