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
 * @var string      $csrfToken
 * @var \EduCloud\Core\Auth\AuthUser|null $currentUser
 * @var array<string, mixed>|null $jsonLd structured data (schema.org), public pages only
 */
$title = $pageTitle ?? $appName;
$jsStrings = [
    'network_error' => t('js.network_error'),
    'timeout' => t('js.timeout'),
    'generic_error' => t('js.generic_error'),
    'passwords_mismatch' => t('js.passwords_mismatch'),
    'required' => t('js.required'),
    'done' => t('js.done'),
];
?><!doctype html>
<html lang="<?= e($locale) ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="app-base" content="<?= e(\EduCloud\Core\Url::baseHref()) ?>">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
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
<?php if (isset($jsonLd) && is_array($jsonLd)): ?>
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
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
        <a class="navbar-brand ec-brand" href="<?= e(url('/')) ?>">
            <i class="fa-solid fa-cloud ec-brand-mark" aria-hidden="true"></i> <?= e($appName) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#ec-nav"
                aria-controls="ec-nav" aria-expanded="false" aria-label="Mostrar navegación">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="ec-nav">
            <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a></li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/labs')) ?>"><?= e(t('nav.catalog')) ?></a></li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/courses')) ?>"><?= e(t('nav.courses')) ?></a></li>
<?php if ($currentUser !== null): ?>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/app')) ?>"><?= e(t('nav.dashboard')) ?></a></li>
                <li class="nav-item dropdown">
                    <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown"
                            aria-expanded="false" aria-label="<?= e(t('nav.user_menu')) ?>">
                        <i class="fa-solid fa-circle-user" aria-hidden="true"></i> <?= e($currentUser->displayName) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-body-secondary"><?= e($currentUser->email) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><button class="dropdown-item" type="button" data-ec-logout><?= e(t('nav.logout')) ?></button></li>
                    </ul>
                </li>
<?php else: ?>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/login')) ?>"><?= e(t('nav.login')) ?></a></li>
                <li class="nav-item"><a class="btn btn-primary btn-sm" href="<?= e(url('/register')) ?>"><?= e(t('nav.register')) ?></a></li>
<?php endif; ?>
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
<script src="<?= e(asset('js/features/auth.js')) ?>"></script>
</body>
</html>
