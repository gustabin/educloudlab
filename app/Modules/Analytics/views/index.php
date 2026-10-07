<?php
/**
 * @var array<string, mixed>                $workspace
 * @var array<string, array<string, string>> $catalog
 * @var bool $canEdit
 */
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces')) ?>"><?= e(t('ws.title')) ?></a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces/' . $workspace['id'])) ?>"><?= e($workspace['name']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e(t('analytics.title')) ?></li>
    </ol>
</nav>

<div class="ec-page-header mb-3">
    <div>
        <h1 class="h3 mb-1"><?= e(t('analytics.title')) ?></h1>
        <p class="text-body-secondary mb-0 small"><?= e(t('analytics.lead')) ?></p>
    </div>
</div>

<div class="row g-3" id="analytics" data-workspace-id="<?= e($workspace['id']) ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>">
    <aside class="col-12 col-lg-3">
        <section class="ec-card mb-3" aria-labelledby="an-models-title">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0" id="an-models-title"><?= e(t('analytics.models')) ?></h2>
<?php if ($canEdit): ?>
                <button class="btn btn-sm btn-outline-primary" type="button" data-an-new="model" aria-label="<?= e(t('analytics.new_model')) ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
<?php endif; ?>
            </div>
            <div id="an-models" aria-busy="true" aria-live="polite"><div class="ec-skeleton ec-skeleton-sm" aria-hidden="true"></div></div>
        </section>
        <section class="ec-card mb-3" aria-labelledby="an-dashboards-title">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0" id="an-dashboards-title"><?= e(t('analytics.dashboards')) ?></h2>
<?php if ($canEdit): ?>
                <button class="btn btn-sm btn-outline-primary" type="button" data-an-new="dashboard" aria-label="<?= e(t('analytics.new_dashboard')) ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
<?php endif; ?>
            </div>
            <div id="an-dashboards" aria-busy="true" aria-live="polite"><div class="ec-skeleton ec-skeleton-sm" aria-hidden="true"></div></div>
        </section>
        <section class="ec-card" aria-labelledby="an-catalog-title">
            <h2 class="h6" id="an-catalog-title"><?= e(t('analytics.catalog')) ?></h2>
<?php if ($catalog === []): ?>
            <p class="small text-body-secondary mb-0"><?= e(t('analytics.catalog_empty')) ?></p>
<?php else: ?>
            <ul class="list-unstyled small mb-0 ec-catalog-list">
<?php foreach ($catalog as $table => $columns): ?>
                <li class="mb-2">
                    <span class="font-monospace fw-semibold"><?= e($table) ?></span>
                    <span class="d-block text-body-secondary font-monospace"><?= e(implode(', ', array_map(static fn (string $c, string $type): string => "$c $type", array_keys($columns), $columns))) ?></span>
                </li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
        </section>
    </aside>

    <div class="col-12 col-lg-9">
        <section class="ec-card mb-3 d-none" id="an-editor" aria-labelledby="an-editor-title">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">
                <div class="flex-grow-1">
                    <h2 class="h6" id="an-editor-title"></h2>
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1" for="an-name"><?= e(t('analytics.name')) ?></label>
                            <input class="form-control form-control-sm" id="an-name" name="name" maxlength="80" autocomplete="off"<?= $canEdit ? '' : ' readonly' ?>>
                        </div>
                        <div class="col-12 col-md-6 d-none" id="an-model-field">
                            <label class="form-label small mb-1" for="an-model"><?= e(t('analytics.model')) ?></label>
                            <select class="form-select form-select-sm" id="an-model" name="model_id"<?= $canEdit ? '' : ' disabled' ?>></select>
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
<?php if ($canEdit): ?>
                    <label class="visually-hidden" for="an-template"><?= e(t('analytics.template')) ?></label>
                    <select class="form-select form-select-sm w-auto" id="an-template"></select>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="an-validate"><?= e(t('analytics.validate')) ?></button>
                    <button class="btn btn-sm btn-outline-primary" type="button" id="an-save"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> <?= e(t('analytics.save')) ?></button>
<?php endif; ?>
                    <a class="btn btn-sm btn-primary d-none" id="an-open" href="#"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> <?= e(t('analytics.open_dashboard')) ?></a>
<?php if ($canEdit): ?>
                    <button class="btn btn-sm btn-outline-danger" type="button" id="an-delete" aria-label="<?= e(t('analytics.delete')) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
<?php endif; ?>
                </div>
            </div>
            <label class="form-label small" for="an-definition" id="an-definition-label"><?= e(t('analytics.definition')) ?></label>
            <textarea id="an-definition" class="form-control font-monospace" rows="16" spellcheck="false" aria-describedby="an-definition-help"></textarea>
            <div id="an-definition-help" class="form-text"></div>
            <div id="an-problems" class="alert alert-danger small mt-2 d-none" role="alert"></div>
        </section>

        <section class="ec-card mb-3 d-none" id="an-explore" aria-labelledby="an-explore-title">
            <h2 class="h6" id="an-explore-title"><?= e(t('analytics.explore')) ?></h2>
            <p class="small text-body-secondary"><?= e(t('analytics.explore_help')) ?></p>
            <form class="row g-2 align-items-end" id="an-explore-form" novalidate>
                <fieldset class="col-12 col-md-6">
                    <legend class="form-label small mb-1"><?= e(t('analytics.measures')) ?></legend>
                    <div id="an-explore-measures" class="d-flex flex-wrap gap-2"></div>
                </fieldset>
                <div class="col-8 col-md-4">
                    <label class="form-label small mb-1" for="an-explore-dimension"><?= e(t('analytics.dimension')) ?></label>
                    <select class="form-select form-select-sm" id="an-explore-dimension"></select>
                </div>
                <div class="col-4 col-md-2">
                    <button class="btn btn-sm btn-primary w-100" type="submit"><i class="fa-solid fa-play" aria-hidden="true"></i> <?= e(t('analytics.run')) ?></button>
                </div>
            </form>
            <div id="an-explore-status" class="small text-body-secondary mt-2" aria-live="polite"></div>
            <div id="an-explore-result" class="mt-2"></div>
        </section>

        <div class="ec-empty" id="an-empty">
            <span class="ec-feature-icon"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i></span>
            <h2 class="h5"><?= e(t('analytics.empty_title')) ?></h2>
            <p class="text-body-secondary mb-0"><?= e(t('analytics.empty_text')) ?></p>
        </div>
    </div>
</div>
