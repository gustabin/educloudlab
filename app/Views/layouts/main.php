<?php
/**
 * Public layout.
 * @var string      $content
 * @var string      $appName
 * @var string      $locale
 * @var string|null $pageTitle
 * @var string|null $metaDescription
 * @var string|null $canonical
 * @var bool        $indexable
 */
$title = $pageTitle ?? $appName;
$jsStrings = [
    'network_error' => t('js.network_error'),
    'timeout' => t('js.timeout'),
    'generic_error' => t('js.generic_error'),
];
?><!doctype html>
<html lang="<?= e($locale) ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
<?php if ($metaDescription !== null): ?>
    <meta name="description" content="<?= e($metaDescription) ?>">
<?php endif; ?>
    <meta name="robots" content="<?= $indexable ? 'index, follow' : 'noindex, nofollow' ?>">
<?php if ($canonical !== null): ?>
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
<?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e($appName) ?>">
    <meta property="og:title" content="<?= e($title) ?>">
<?php if ($metaDescription !== null): ?>
    <meta property="og:description" content="<?= e($metaDescription) ?>">
<?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/sweetalert2/sweetalert2.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>

<header class="ec-site-header">
    <nav class="navbar navbar-expand-md container" aria-label="Principal">
        <a class="navbar-brand ec-brand" href="/">
            <i class="fa-solid fa-cloud ec-brand-mark" aria-hidden="true"></i> <?= e($appName) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#ec-nav"
                aria-controls="ec-nav" aria-expanded="false" aria-label="Mostrar navegación">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="ec-nav">
            <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
                <li class="nav-item"><a class="nav-link" href="/"><?= e(t('nav.home')) ?></a></li>
                <li class="nav-item"><a class="nav-link" href="/courses"><?= e(t('nav.catalog')) ?></a></li>
                <li class="nav-item"><a class="nav-link" href="/login"><?= e(t('nav.login')) ?></a></li>
                <li class="nav-item"><a class="btn btn-primary btn-sm" href="/register"><?= e(t('nav.register')) ?></a></li>
            </ul>
        </div>
    </nav>
</header>

<main id="main" tabindex="-1">
<?= $content /* already-rendered, escaped template output */ ?>
</main>

<footer class="ec-site-footer">
    <div class="container">
        <p class="mb-0"><?= e(t('footer.disclaimer')) ?></p>
    </div>
</footer>

<div id="ec-live" class="visually-hidden" aria-live="polite"></div>
<script type="application/json" id="ec-i18n"><?= json_encode($jsStrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.min.js')) ?>"></script>
<script src="<?= e(asset('js/core/api.js')) ?>"></script>
</body>
</html>
