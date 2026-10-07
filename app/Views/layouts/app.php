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
    'ds.col.name', 'ds.col.layer', 'ds.col.rows', 'ds.col.columns', 'ds.col.size', 'ds.col.status', 'ds.col.actions',
    'ds.status.provisioning', 'ds.ingest', 'ds.ingest_title', 'ds.ingest_text', 'ds.ingest_label', 'ds.ingest_invalid',
    'ds.delete_title', 'ds.delete_text', 'ds.uploaded', 'ds.preview_named', 'ds.ingest_named', 'ds.delete_named',
    'ds.select_file', 'ds.preview_empty', 'ds.preview_note', 'status.failed', 'common.cancel',
    'sql.catalog_layer_empty', 'sql.insert', 'sql.catalog_error', 'sql.placeholder', 'sql.failed', 'sql.status_failed',
    'sql.result_expired', 'sql.results_empty', 'sql.rows', 'sql.duration', 'sql.truncated', 'sql.status_queued',
    'sql.status_running', 'sql.history_empty', 'sql.state.queued', 'sql.state.running', 'sql.state.succeeded',
    'sql.state.failed', 'sql.state.timed_out', 'sql.state.cancelled', 'sql.saving_title', 'sql.saving_text', 'sql.saved',
    'sql.save_failed', 'sql.results',
    'labs.state.in_progress', 'labs.state.validating', 'labs.state.completed', 'labs.state.abandoned', 'labs.state.expired',
    'labs.expires', 'labs.passed', 'labs.failed', 'labs.result_title', 'labs.result_text', 'labs.validation_error',
    'labs.answer_saved', 'labs.answer_empty', 'labs.hint_confirm_title', 'labs.hint_confirm_text', 'labs.hint_confirm',
    'labs.abandon', 'labs.abandon_title', 'labs.abandon_text', 'labs.validating', 'labs.load_error',
    'courses.joined_text', 'courses.go_course', 'courses.code_confirm_title', 'courses.code_confirm_text', 'courses.code_generate',
    'courses.unassign', 'courses.unassign_title', 'courses.unassign_text',
    'admin.empty', 'admin.page', 'admin.prev', 'admin.next', 'admin.enable', 'admin.disable', 'admin.enable_title',
    'admin.disable_title', 'admin.disable_text', 'admin.card.users', 'admin.card.users_detail', 'admin.card.queue',
    'admin.card.queue_detail', 'admin.card.storage', 'admin.card.storage_detail', 'admin.card.labs', 'admin.card.labs_detail',
    'admin.col.type', 'admin.col.status', 'admin.col.tenant', 'admin.col.user', 'admin.col.error', 'admin.col.queued',
    'admin.col.duration', 'admin.col.when', 'admin.col.action', 'admin.col.outcome', 'admin.col.actor', 'admin.col.resource',
    'admin.col.name', 'admin.col.email', 'admin.col.last_login', 'admin.col.storage', 'admin.col.actions',
    'pipelines.state.queued', 'pipelines.state.running', 'pipelines.state.succeeded', 'pipelines.state.failed', 'pipelines.state.cancelled', 'pipelines.state.timed_out', 'pipelines.step.succeeded', 'pipelines.step.failed', 'pipelines.step.warning', 'pipelines.step.skipped', 'pipelines.step.running', 'pipelines.col.step', 'pipelines.col.type', 'pipelines.col.status', 'pipelines.col.rows', 'pipelines.col.ms', 'pipelines.col.message', 'pipelines.none', 'pipelines.no_runs', 'pipelines.running', 'pipelines.cancel', 'pipelines.cancelling', 'pipelines.invalid_json', 'pipelines.save_first', 'pipelines.delete_title', 'pipelines.delete_text', 'ds.lineage_named', 'ds.lineage_upstream', 'ds.lineage_downstream', 'ds.lineage_none', 'ds.lineage_via.ingest', 'ds.lineage_via.transform', 'ds.lineage_via.pipeline',
    'storage.no_containers', 'storage.objects_count', 'storage.lifecycle_summary', 'storage.no_lifecycle', 'storage.no_objects', 'storage.empty_container',
    'storage.col.key', 'storage.col.size', 'storage.col.tier', 'storage.col.metadata', 'storage.col.actions', 'storage.tier_of',
    'storage.download_named', 'storage.delete_named', 'storage.archived_hint', 'storage.open',
    'analytics.new_model', 'analytics.new_dashboard', 'analytics.edit_model', 'analytics.edit_dashboard', 'analytics.no_models',
    'analytics.no_dashboards', 'analytics.model_summary', 'analytics.dashboard_summary', 'analytics.template', 'analytics.template.star',
    'analytics.template.single', 'analytics.template.sales', 'analytics.delete_title', 'analytics.model_help', 'analytics.dashboard_help',
    'analytics.invalid_json', 'analytics.model_first', 'analytics.explore', 'analytics.explore_help', 'analytics.no_dimension',
    'analytics.loading', 'analytics.updated', 'analytics.failed', 'analytics.expired', 'analytics.no_result', 'analytics.no_rows',
    'analytics.no_value', 'analytics.truncated', 'analytics.show_data', 'analytics.chart_summary', 'analytics.widget.bar',
    'analytics.widget.line',
    'content.module_title', 'content.module_summary', 'content.new_module', 'content.edit_module', 'content.save', 'content.delete_title', 'content.new_lesson',
    'content.lesson_title', 'content.create', 'content.body_placeholder', 'notif.label', 'notif.label_unread', 'notif.empty', 'notif.unread',
    'notebooks.none', 'notebooks.delete_title', 'notebooks.cell_code', 'notebooks.cell_text', 'notebooks.move_up', 'notebooks.move_down',
    'notebooks.remove_cell', 'notebooks.running', 'notebooks.last_run', 'notebooks.output_of', 'notebooks.rows_shown', 'notebooks.line',
] as $key) {
    $jsStrings[$key] = t($key);
}
$nav = [
    ['key' => 'home', 'href' => '/app', 'icon' => 'fa-house', 'label' => t('nav.home')],
    ['key' => 'workspaces', 'href' => '/app/workspaces', 'icon' => 'fa-layer-group', 'label' => t('ws.title')],
    ['key' => 'data', 'href' => null, 'icon' => 'fa-database', 'label' => t('nav.data')],
    ['key' => 'sql', 'href' => '/app/sql', 'icon' => 'fa-terminal', 'label' => t('nav.sql')],
    ['key' => 'courses', 'href' => '/app/courses', 'icon' => 'fa-chalkboard-user', 'label' => t('nav.courses')],
    ['key' => 'labs', 'href' => '/app/labs', 'icon' => 'fa-flask', 'label' => t('nav.labs')],
];
if ($currentUser->isPlatformAdmin) {
    $nav[] = ['key' => 'admin', 'href' => '/app/admin', 'icon' => 'fa-gauge-high', 'label' => t('nav.admin')];
}
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
<?php foreach ($extraStyles ?? [] as $style): ?>
    <link rel="stylesheet" href="<?= e(asset((string) $style)) ?>">
<?php endforeach; ?>
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
        <div class="dropdown" id="ec-notifications">
            <button class="btn btn-sm btn-outline-secondary position-relative" type="button" id="ec-notif-toggle" data-bs-toggle="dropdown"
                    data-bs-auto-close="outside" aria-expanded="false" aria-label="<?= e(t('notif.label')) ?>">
                <i class="fa-solid fa-bell" aria-hidden="true"></i>
                <span class="ec-notif-count d-none" id="ec-notif-count" aria-hidden="true"></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end ec-notif-menu p-0">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <h2 class="h6 mb-0"><?= e(t('notif.title')) ?></h2>
                    <button class="btn btn-link btn-sm p-0" type="button" id="ec-notif-read-all"><?= e(t('notif.read_all')) ?></button>
                </div>
                <div id="ec-notif-list" aria-live="polite"></div>
            </div>
        </div>
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
<script src="<?= e(asset('js/core/notifications.js')) ?>"></script>
<script src="<?= e(asset('js/features/auth.js')) ?>"></script>
<script src="<?= e(asset('js/features/portal.js')) ?>"></script>
<script src="<?= e(asset('js/features/workspaces.js')) ?>"></script>
<script src="<?= e(asset('js/features/datasets.js')) ?>"></script>
<?php foreach ($extraScripts ?? [] as $script): ?>
<script src="<?= e(asset((string) $script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
