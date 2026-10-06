<?php
/**
 * @var bool $canCreate
 * @var int  $quota
 */
?>
<div class="ec-page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(t('ws.title')) ?></h1>
        <p class="text-body-secondary mb-0"><?= e(t('ws.lead')) ?></p>
    </div>
<?php if ($canCreate): ?>
    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#ws-create-modal">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('ws.create')) ?>
    </button>
<?php endif; ?>
</div>

<div class="row g-2 mb-3">
    <div class="col-12 col-md-6">
        <label class="visually-hidden" for="ws-search"><?= e(t('ws.search')) ?></label>
        <input class="form-control" id="ws-search" type="search" placeholder="<?= e(t('ws.search')) ?>" maxlength="80" autocomplete="off">
    </div>
    <div class="col-12 col-md-3">
        <label class="visually-hidden" for="ws-sort"><?= e(t('ws.sort')) ?></label>
        <select class="form-select" id="ws-sort">
            <option value="updated_at:desc"><?= e(t('ws.sort.updated')) ?></option>
            <option value="created_at:desc"><?= e(t('ws.sort.created')) ?></option>
            <option value="name:asc"><?= e(t('ws.sort.name')) ?></option>
        </select>
    </div>
</div>

<div id="ws-list" data-ec-page="workspaces" aria-busy="true" aria-live="polite">
    <div class="row g-3">
<?php for ($i = 0; $i < 3; $i++): ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="ec-card ec-skeleton" aria-hidden="true"></div></div>
<?php endfor; ?>
    </div>
</div>
<nav class="mt-3" aria-label="<?= e(t('ws.pagination')) ?>"><ul class="pagination pagination-sm" id="ws-pagination"></ul></nav>

<template id="ws-empty-template">
    <div class="ec-empty">
        <span class="ec-feature-icon"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span>
        <h2 class="h5"><?= e(t('portal.dashboard.empty_title')) ?></h2>
        <p class="text-body-secondary mb-0"><?= e(t('ws.empty_text')) ?></p>
    </div>
</template>
<template id="ws-noresults-template">
    <div class="ec-empty"><p class="mb-0"><?= e(t('ws.no_results')) ?></p></div>
</template>
<template id="ws-error-template">
    <div class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
        <span><?= e(t('ws.load_error')) ?></span>
        <button class="btn btn-sm btn-outline-danger" type="button" data-ec-retry><?= e(t('common.retry')) ?></button>
    </div>
</template>

<?php if ($canCreate): ?>
<div class="modal fade" id="ws-create-modal" tabindex="-1" aria-labelledby="ws-create-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="ws-create-form" novalidate>
            <div class="modal-header">
                <h2 class="modal-title h5" id="ws-create-title"><?= e(t('ws.create')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('nav.close')) ?>"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="ws-name"><?= e(t('ws.field.name')) ?></label>
                    <input class="form-control" id="ws-name" name="name" required minlength="2" maxlength="80" autocomplete="off">
                    <div class="form-text"><?= e(t('ws.field.name_help')) ?></div>
                </div>
                <div class="mb-1">
                    <label class="form-label" for="ws-description"><?= e(t('ws.field.description')) ?> <span class="text-body-secondary">(<?= e(t('common.optional')) ?>)</span></label>
                    <textarea class="form-control" id="ws-description" name="description" rows="3" maxlength="500"></textarea>
                </div>
                <p class="small text-body-secondary mt-3 mb-0"><?= e(t('ws.quota_hint', ['n' => $quota])) ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(t('ws.create')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
