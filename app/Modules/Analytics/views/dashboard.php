<?php
/**
 * @var array<string, mixed> $dashboard
 * @var bool $canRender
 */
$definition = $dashboard['definition'];
$labels = array_column($dashboard['model']['dimensions'], 'label', 'name');
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces')) ?>"><?= e(t('ws.title')) ?></a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces/' . $dashboard['workspace_id'] . '/analytics')) ?>"><?= e(t('analytics.title')) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($dashboard['name']) ?></li>
    </ol>
</nav>

<div class="ec-page-header mb-3">
    <div>
        <h1 class="h3 mb-1"><?= e($dashboard['name']) ?></h1>
        <p class="text-body-secondary mb-0 small"><?= e(t('analytics.dashboard_model', ['model' => $dashboard['model']['name']])) ?></p>
    </div>
</div>

<div id="dashboard" data-dashboard-id="<?= e($dashboard['id']) ?>" data-can-render="<?= $canRender ? '1' : '0' ?>">
    <script type="application/json" id="dashboard-data"><?= json_encode(
        ['definition' => $definition, 'model' => $dashboard['model']],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    ) ?></script>

<?php if (isset($definition['date_filter']) || !empty($definition['filters'])): ?>
    <form class="ec-card mb-3 row g-2 align-items-end" id="db-filters" novalidate>
        <h2 class="visually-hidden"><?= e(t('analytics.filters')) ?></h2>
<?php if (isset($definition['date_filter'])): ?>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="db-date-from"><?= e(t('analytics.date_from')) ?></label>
            <input class="form-control form-control-sm" type="date" id="db-date-from" name="date_from">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="db-date-to"><?= e(t('analytics.date_to')) ?></label>
            <input class="form-control form-control-sm" type="date" id="db-date-to" name="date_to">
        </div>
<?php endif; ?>
<?php foreach ($definition['filters'] ?? [] as $f): ?>
        <div class="col-12 col-md-3">
            <label class="form-label small mb-1" for="db-filter-<?= e($f['dimension']) ?>"><?= e($labels[$f['dimension']] ?? $f['dimension']) ?></label>
            <select class="form-select form-select-sm" id="db-filter-<?= e($f['dimension']) ?>" data-filter="<?= e($f['dimension']) ?>">
                <option value=""><?= e(t('analytics.all')) ?></option>
            </select>
        </div>
<?php endforeach; ?>
        <div class="col-12 col-md-2">
            <button class="btn btn-sm btn-primary w-100" type="submit"><?= e(t('analytics.apply')) ?></button>
        </div>
    </form>
<?php endif; ?>

    <p class="small text-body-secondary" id="db-status" aria-live="polite"></p>
<?php if (!$canRender): ?>
    <div class="alert alert-info small"><?= e(t('analytics.cannot_render')) ?></div>
<?php endif; ?>
    <div class="row g-3" id="db-widgets">
<?php foreach ($definition['widgets'] as $w): ?>
        <div class="<?= $w['type'] === 'kpi' ? 'col-6 col-md-3' : 'col-12 col-xl-6' ?>">
            <section class="ec-card h-100 ec-widget" data-widget="<?= e($w['id']) ?>" aria-labelledby="w-<?= e($w['id']) ?>-title">
                <h2 class="h6" id="w-<?= e($w['id']) ?>-title"><?= e($w['title']) ?></h2>
                <div class="ec-widget-body" aria-busy="true"><div class="ec-skeleton ec-skeleton-sm" aria-hidden="true"></div></div>
            </section>
        </div>
<?php endforeach; ?>
    </div>
</div>
