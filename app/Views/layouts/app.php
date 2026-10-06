<?php
/**
 * Authenticated portal layout: top bar (tenant switcher, user menu) + sidebar navigation.
 * @var string      $content
 * @var string      $appName
 * @var string      $locale
 * @var string|null $pageTitle
 * @var string      $csrfToken
 * @var \EduCloud\Core\Auth\AuthUser          $currentUser
 * @var \EduCloud\Core\Auth\TenantContext     $tenantContext
 * @var string|null $tenantName
 * @var list<array{tenant_public_id: string, tenant_name: string, tenant_type: string, role: string}> $memberships
 * @var string|null $activeNav
 */
$activeNav = $activeNav ?? 'home';
$jsStrings = [
    'network_error' => t('js.network_error'),
    'timeout' => t('js.timeout'),
    'generic_error' => t('js.generic_error'),
    'passwords_mismatch' => t('js.passwords_mismatch'),
    'required' => t('js.required'),
    'done' => t('js.done'),
];
foreach ([
    'common.delete', 'common.cancel', 'common.yes', 'common.no', 'confirm.type_name', 'confirm.mismatch',
    'status.active', 'status.provisioning', 'status.failed', 'status.deleting', 'status.deleted',
    'ws.no_description', 'ws.owner', 'ws.updated', 'ws.resources_count', 'ws.delete_title', 'ws.delete_text',
    'res.type.storage', 'res.type.lakehouse', 'res.type.dataset', 'res.type.pipeline', 'res.type.notebook', 'res.type.dashboard',
    'res.col.name', 'res.col.type', 'res.col.status', 'res.col.region', 'res.col.config', 'res.col.actions',
    'res.delete_title', 'res.delete_text', 'res.delete_named', 'res.deleted',
] as $key) {
    $jsStrings[$key] = t($key);
}
$nav = [
    ['key' => 'home', 'href' => '/app', 'icon' => 'fa-house', 'label' => t('nav.home')],
    ['key' => 'workspaces', 'href' => '/app/workspaces', 'icon' => 'fa-layer-group', 'label' => t('ws.title')],
    ['key' => 'data', 'href' => null, 'icon' => 'fa-database', 'label' => t('nav.data')],
    ['key' => 'sql', 'href' => null, 'icon' => 'fa-terminal', 'label' => t('nav.sql')],
    ['key' => 'labs', 'href' => null, 'icon' => 'fa-flask', 'label' => t('nav.labs')],
];
$roles = ['org_admin' => t('role.org_admin'), 'instructor' => t('role.instructor'), 'student' => t('role.student'), 'read_only' => t('role.read_only')];
?><!doctype html>
<html lang="<?= e($locale) ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="app-base" content="<?= e(\EduCloud\Core\Url::baseHref()) ?>">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle ?? $appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/sweetalert2/sweetalert2.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="ec-app">
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>

<header class="ec-topbar">
    <button class="btn btn-link d-md-none ec-topbar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#ec-sidebar"
            aria-controls="ec-sidebar" aria-label="<?= e(t('nav.menu')) ?>">
        <i class="fa-solid fa-bars" aria-hidden="true"></i>
    </button>
    <a class="ec-brand" href="<?= e(url('/app')) ?>"><i class="fa-solid fa-cloud ec-brand-mark" aria-hidden="true"></i> <?= e($appName) ?></a>

    <div class="ms-auto d-flex align-items-center gap-2">
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle ec-tenant-switcher" type="button" data-bs-toggle="dropdown"
                    aria-expanded="false" aria-label="<?= e(t('tenant.switch')) ?>">
                <i class="fa-solid fa-building" aria-hidden="true"></i>
                <span class="d-none d-sm-inline"><?= e($tenantName) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h2 class="dropdown-header"><?= e(t('tenant.switch')) ?></h2></li>
<?php foreach ($memberships as $m): ?>
<?php $isActive = $m['tenant_public_id'] === $tenantContext->tenantPublicId; ?>
                <li>
                    <button class="dropdown-item d-flex justify-content-between gap-3<?= $isActive ? ' active' : '' ?>" type="button"
                            data-ec-switch-tenant="<?= e($m['tenant_public_id']) ?>"<?= $isActive ? ' aria-current="true" disabled' : '' ?>>
                        <span><?= e($m['tenant_name']) ?></span>
                        <small class="text-body-secondary"><?= e($roles[$m['role']] ?? $m['role']) ?></small>
                    </button>
                </li>
<?php endforeach; ?>
            </ul>
        </div>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown"
                    aria-expanded="false" aria-label="<?= e(t('nav.user_menu')) ?>">
                <i class="fa-solid fa-circle-user" aria-hidden="true"></i>
                <span class="d-none d-sm-inline"><?= e($currentUser->displayName) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text small text-body-secondary"><?= e($currentUser->email) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><button class="dropdown-item" type="button" data-ec-logout><?= e(t('nav.logout')) ?></button></li>
            </ul>
        </div>
    </div>
</header>

<div class="ec-shell">
    <nav class="offcanvas-md offcanvas-start ec-sidebar" id="ec-sidebar" tabindex="-1" aria-label="<?= e(t('nav.main')) ?>">
        <div class="offcanvas-header d-md-none">
            <span class="ec-brand"><?= e($appName) ?></span>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#ec-sidebar" aria-label="<?= e(t('nav.close')) ?>"></button>
        </div>
        <ul class="nav flex-column">
<?php foreach ($nav as $item): ?>
            <li class="nav-item">
<?php if ($item['href'] === null): ?>
                <span class="nav-link disabled" aria-disabled="true">
                    <i class="fa-solid <?= e($item['icon']) ?> fa-fw" aria-hidden="true"></i> <?= e($item['label']) ?>
                    <span class="badge text-bg-light border ms-1"><?= e(t('nav.soon')) ?></span>
                </span>
<?php else: ?>
                <a class="nav-link<?= $activeNav === $item['key'] ? ' active' : '' ?>" href="<?= e(url($item['href'])) ?>"
                   <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
                    <i class="fa-solid <?= e($item['icon']) ?> fa-fw" aria-hidden="true"></i> <?= e($item['label']) ?>
                </a>
<?php endif; ?>
            </li>
<?php endforeach; ?>
        </ul>
        <p class="ec-sidebar-role small text-body-secondary">
            <?= e(t('portal.dashboard.role')) ?> <?= e($roles[$tenantContext->role] ?? $tenantContext->role) ?>
        </p>
    </nav>

    <main id="main" class="ec-main" tabindex="-1">
<?= $content /* already-rendered, escaped template output */ ?>
    </main>
</div>

<div id="ec-live" class="visually-hidden" aria-live="polite"></div>
<script type="application/json" id="ec-i18n"><?= json_encode($jsStrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.min.js')) ?>"></script>
<script src="<?= e(asset('js/core/api.js')) ?>"></script>
<script src="<?= e(asset('js/features/auth.js')) ?>"></script>
<script src="<?= e(asset('js/features/portal.js')) ?>"></script>
<script src="<?= e(asset('js/features/workspaces.js')) ?>"></script>
</body>
</html>
