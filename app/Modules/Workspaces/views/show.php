<?php
/**
 * @var array<string, mixed> $workspace
 * @var bool $canUpdate
 * @var bool $canDelete
 * @var bool $canCreate
 * @var int  $uploadMaxBytes
 */
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces')) ?>"><?= e(t('ws.title')) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($workspace['name']) ?></li>
    </ol>
</nav>

<div class="ec-page-header" id="ws-detail" data-ec-page="workspace"
     data-workspace-id="<?= e($workspace['id']) ?>" data-workspace-name="<?= e($workspace['name']) ?>"
     data-can-delete="<?= $canDelete ? '1' : '0' ?>">
    <div>
        <h1 class="h3 mb-1" id="ws-title"><?= e($workspace['name']) ?></h1>
        <p class="text-body-secondary mb-0" id="ws-description-text"><?= e($workspace['description'] ?? t('ws.no_description')) ?></p>
        <p class="small text-body-secondary mt-1 mb-0">
            <?= e(t('ws.owner')) ?> <?= e($workspace['owner']['display_name']) ?> ·
            <span class="ec-status ec-status-<?= e($workspace['status']) ?>"><?= e(t('status.' . $workspace['status'])) ?></span>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('/app/workspaces/' . $workspace['id'] . '/sql')) ?>">
            <i class="fa-solid fa-terminal" aria-hidden="true"></i> <?= e(t('sql.open')) ?>
        </a>
<?php if ($canUpdate): ?>
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#ws-edit-modal">
            <i class="fa-solid fa-pen" aria-hidden="true"></i> <?= e(t('common.edit')) ?>
        </button>
<?php endif; ?>
<?php if ($canDelete): ?>
        <button class="btn btn-outline-danger" type="button" data-ec-delete-workspace>
            <i class="fa-solid fa-trash" aria-hidden="true"></i> <?= e(t('common.delete')) ?>
        </button>
<?php endif; ?>
    </div>
</div>

<section aria-labelledby="res-title">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 id="res-title" class="h5 mb-0"><?= e(t('res.title')) ?></h2>
<?php if ($canCreate): ?>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#res-create-modal">
            <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('res.create')) ?>
        </button>
<?php endif; ?>
    </div>
    <div id="res-list" aria-busy="true" aria-live="polite">
        <div class="ec-card ec-skeleton" aria-hidden="true"></div>
    </div>
</section>


<section class="mt-5" aria-labelledby="ds-title" id="ds-section" data-max-mb="<?= e((int) round($uploadMaxBytes / 1048576)) ?>">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 id="ds-title" class="h5 mb-0"><?= e(t('ds.title')) ?></h2>
    </div>
    <p class="text-body-secondary small"><?= e(t('ds.lead')) ?></p>
<?php if ($canCreate): ?>
    <form class="ec-card mb-3" id="ds-upload-form" novalidate>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="ds-name"><?= e(t('ds.field.name')) ?></label>
                <input class="form-control" id="ds-name" name="name" required maxlength="63" pattern="[a-z][a-z0-9_]*"
                       autocomplete="off" aria-describedby="ds-name-help" placeholder="customers">
                <div id="ds-name-help" class="form-text"><?= e(t('ds.field.name_help')) ?></div>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label" for="ds-file"><?= e(t('ds.field.file')) ?></label>
                <input class="form-control" id="ds-file" name="file" type="file" accept=".csv,text/csv" required aria-describedby="ds-file-help">
                <div id="ds-file-help" class="form-text"><?= e(t('ds.field.file_help', ['mb' => (int) round($uploadMaxBytes / 1048576)])) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-upload" aria-hidden="true"></i> <?= e(t('ds.upload')) ?></button>
            </div>
        </div>
    </form>
<?php endif; ?>
    <div id="ds-list" aria-busy="true" aria-live="polite">
        <div class="ec-card ec-skeleton" aria-hidden="true"></div>
    </div>
</section>

<template id="ds-empty-template">
    <div class="ec-empty">
        <span class="ec-feature-icon"><i class="fa-solid fa-database" aria-hidden="true"></i></span>
        <h3 class="h6"><?= e(t('ds.empty_title')) ?></h3>
        <p class="text-body-secondary mb-0"><?= e(t('ds.empty_text')) ?></p>
    </div>
</template>
<template id="ds-error-template">
    <div class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
        <span><?= e(t('ds.load_error')) ?></span>
        <button class="btn btn-sm btn-outline-danger" type="button" data-ec-retry><?= e(t('common.retry')) ?></button>
    </div>
</template>

<div class="modal fade" id="ds-preview-modal" tabindex="-1" aria-labelledby="ds-preview-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="ds-preview-title"><?= e(t('ds.preview')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('nav.close')) ?>"></button>
            </div>
            <div class="modal-body" id="ds-preview-body"></div>
        </div>
    </div>
</div>

<template id="res-empty-template">
    <div class="ec-empty">
        <span class="ec-feature-icon"><i class="fa-solid fa-cubes" aria-hidden="true"></i></span>
        <h3 class="h6"><?= e(t('res.empty_title')) ?></h3>
        <p class="text-body-secondary mb-0"><?= e(t('res.empty_text')) ?></p>
    </div>
</template>
<template id="res-error-template">
    <div class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
        <span><?= e(t('res.load_error')) ?></span>
        <button class="btn btn-sm btn-outline-danger" type="button" data-ec-retry><?= e(t('common.retry')) ?></button>
    </div>
</template>

<?php if ($canUpdate): ?>
<div class="modal fade" id="ws-edit-modal" tabindex="-1" aria-labelledby="ws-edit-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="ws-edit-form" novalidate>
            <div class="modal-header">
                <h2 class="modal-title h5" id="ws-edit-title"><?= e(t('ws.edit')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('nav.close')) ?>"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="ws-edit-name"><?= e(t('ws.field.name')) ?></label>
                    <input class="form-control" id="ws-edit-name" name="name" required minlength="2" maxlength="80" value="<?= e($workspace['name']) ?>">
                </div>
                <div>
                    <label class="form-label" for="ws-edit-description"><?= e(t('ws.field.description')) ?></label>
                    <textarea class="form-control" id="ws-edit-description" name="description" rows="3" maxlength="500"><?= e($workspace['description']) ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="modal fade" id="res-create-modal" tabindex="-1" aria-labelledby="res-create-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="res-create-form" novalidate>
            <div class="modal-header">
                <h2 class="modal-title h5" id="res-create-title"><?= e(t('res.create')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('nav.close')) ?>"></button>
            </div>
            <div class="modal-body">
                <fieldset class="mb-3">
                    <legend class="form-label fs-6"><?= e(t('res.field.type')) ?></legend>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="res-type-storage" value="storage" checked>
                        <label class="form-check-label" for="res-type-storage"><strong><?= e(t('res.type.storage')) ?></strong> — <?= e(t('res.type.storage_help')) ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="res-type-lakehouse" value="lakehouse">
                        <label class="form-check-label" for="res-type-lakehouse"><strong><?= e(t('res.type.lakehouse')) ?></strong> — <?= e(t('res.type.lakehouse_help')) ?></label>
                    </div>
                </fieldset>
                <div class="mb-3">
                    <label class="form-label" for="res-name"><?= e(t('res.field.name')) ?></label>
                    <input class="form-control" id="res-name" name="name" required minlength="2" maxlength="80" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="res-region"><?= e(t('res.field.region')) ?></label>
                    <select class="form-select" id="res-region" name="region" aria-describedby="res-region-help">
                        <option value="edu-local-1">edu-local-1</option>
                        <option value="edu-local-2">edu-local-2</option>
                    </select>
                    <div class="form-text" id="res-region-help"><?= e(t('res.field.region_help')) ?></div>
                </div>
                <div data-ec-config="storage">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="res-tier"><?= e(t('res.config.access_tier')) ?></label>
                            <select class="form-select" id="res-tier" data-config-key="access_tier">
                                <option value="hot">hot</option><option value="cool">cool</option><option value="archive">archive</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="res-redundancy"><?= e(t('res.config.redundancy')) ?></label>
                            <select class="form-select" id="res-redundancy" data-config-key="redundancy">
                                <option value="lrs">LRS</option><option value="zrs">ZRS</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="res-versioning" data-config-key="versioning" data-config-bool>
                        <label class="form-check-label" for="res-versioning"><?= e(t('res.config.versioning')) ?></label>
                    </div>
                </div>
                <div data-ec-config="lakehouse" hidden>
                    <label class="form-label" for="res-layer"><?= e(t('res.config.default_layer')) ?></label>
                    <select class="form-select" id="res-layer" data-config-key="default_layer">
                        <option value="bronze">bronze</option><option value="silver">silver</option><option value="gold">gold</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(t('res.create')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
