<?php
/**
 * @var array<string, mixed> $workspace
 * @var bool $canEdit
 */
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces')) ?>"><?= e(t('ws.title')) ?></a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces/' . $workspace['id'])) ?>"><?= e($workspace['name']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e(t('pipelines.title')) ?></li>
    </ol>
</nav>

<div class="ec-page-header mb-3">
    <div>
        <h1 class="h3 mb-1"><?= e(t('pipelines.title')) ?></h1>
        <p class="text-body-secondary mb-0 small"><?= e(t('pipelines.lead')) ?></p>
    </div>
<?php if ($canEdit): ?>
    <button class="btn btn-primary" type="button" id="pl-new"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('pipelines.new')) ?></button>
<?php endif; ?>
</div>

<div class="row g-3" id="pipelines" data-workspace-id="<?= e($workspace['id']) ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>">
    <aside class="col-12 col-lg-3">
        <section class="ec-card" aria-labelledby="pl-list-title">
            <h2 class="h6" id="pl-list-title"><?= e(t('pipelines.list')) ?></h2>
            <div id="pl-list" aria-busy="true" aria-live="polite"><div class="ec-skeleton ec-skeleton-sm" aria-hidden="true"></div></div>
        </section>
    </aside>

    <div class="col-12 col-lg-9">
        <section class="ec-card mb-3 d-none" id="pl-editor" aria-labelledby="pl-editor-title">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">
                <div class="flex-grow-1">
                    <h2 class="h6" id="pl-editor-title"><?= e(t('pipelines.editor')) ?></h2>
                    <label class="form-label small mb-1" for="pl-name"><?= e(t('pipelines.name')) ?></label>
                    <input class="form-control form-control-sm" id="pl-name" name="name" maxlength="80" autocomplete="off"<?= $canEdit ? '' : ' readonly' ?>>
                </div>
<?php if ($canEdit): ?>
                <div class="d-flex flex-wrap gap-2">
                    <label class="visually-hidden" for="pl-template"><?= e(t('pipelines.template')) ?></label>
                    <select class="form-select form-select-sm w-auto" id="pl-template">
                        <option value=""><?= e(t('pipelines.template')) ?></option>
                        <option value="clean"><?= e(t('pipelines.template.clean')) ?></option>
                        <option value="aggregate"><?= e(t('pipelines.template.aggregate')) ?></option>
                        <option value="raw"><?= e(t('pipelines.template.raw')) ?></option>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="pl-validate"><?= e(t('pipelines.validate')) ?></button>
                    <button class="btn btn-sm btn-outline-primary" type="button" id="pl-save"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> <?= e(t('pipelines.save')) ?></button>
                    <button class="btn btn-sm btn-primary" type="button" id="pl-run"><i class="fa-solid fa-play" aria-hidden="true"></i> <?= e(t('pipelines.run')) ?></button>
                    <button class="btn btn-sm btn-outline-danger" type="button" id="pl-delete" aria-label="<?= e(t('pipelines.delete')) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                </div>
<?php endif; ?>
            </div>
            <label class="form-label small" for="pl-definition" id="pl-definition-label"><?= e(t('pipelines.definition')) ?></label>
            <textarea id="pl-definition" class="form-control font-monospace" rows="16" spellcheck="false" aria-describedby="pl-definition-help"></textarea>
            <div id="pl-definition-help" class="form-text"><?= e(t('pipelines.definition_help')) ?></div>
            <div id="pl-problems" class="alert alert-danger small mt-2 d-none" role="alert"></div>
            <h3 class="h6 mt-3"><?= e(t('pipelines.steps')) ?></h3>
            <ol class="ec-pipeline-steps" id="pl-steps" aria-live="polite"></ol>
        </section>

        <section class="ec-card d-none" id="pl-runs-card" aria-labelledby="pl-runs-title">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0" id="pl-runs-title"><?= e(t('pipelines.runs')) ?></h2>
                <span class="small text-body-secondary" id="pl-run-status" aria-live="polite"></span>
            </div>
            <div id="pl-runs"></div>
        </section>

        <div class="ec-empty" id="pl-empty">
            <span class="ec-feature-icon"><i class="fa-solid fa-diagram-project" aria-hidden="true"></i></span>
            <h2 class="h5"><?= e(t('pipelines.empty_title')) ?></h2>
            <p class="text-body-secondary mb-0"><?= e(t('pipelines.empty_text')) ?></p>
        </div>
    </div>
</div>
