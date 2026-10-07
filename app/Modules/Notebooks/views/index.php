<?php
/**
 * @var array<string, mixed> $workspace
 * @var bool   $canEdit
 * @var string $mode off | demo | docker
 */
$canRun = $canEdit && $mode === 'docker';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces')) ?>"><?= e(t('ws.title')) ?></a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces/' . $workspace['id'])) ?>"><?= e($workspace['name']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e(t('notebooks.title')) ?></li>
    </ol>
</nav>

<div class="ec-page-header mb-3">
    <div>
        <h1 class="h3 mb-1"><?= e(t('notebooks.title')) ?></h1>
        <p class="text-body-secondary mb-0 small"><?= e(t('notebooks.lead')) ?></p>
    </div>
<?php if ($canEdit && $mode !== 'off'): ?>
    <button class="btn btn-primary" type="button" id="nb-new"><i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('notebooks.new')) ?></button>
<?php endif; ?>
</div>

<?php if ($mode !== 'docker'): ?>
<div class="alert alert-info small" role="note">
    <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i><?= e($mode === 'off' ? t('notebooks.mode_off') : t('notebooks.mode_demo')) ?>
</div>
<?php endif; ?>

<div class="row g-3" id="notebooks" data-workspace-id="<?= e($workspace['id']) ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>"
     data-can-run="<?= $canRun ? '1' : '0' ?>">
    <aside class="col-12 col-lg-3">
        <section class="ec-card" aria-labelledby="nb-list-title">
            <h2 class="h6" id="nb-list-title"><?= e(t('notebooks.list')) ?></h2>
            <div id="nb-list" aria-busy="true" aria-live="polite"><div class="ec-skeleton ec-skeleton-sm" aria-hidden="true"></div></div>
        </section>
        <section class="ec-card mt-3 small" aria-labelledby="nb-help-title">
            <h2 class="h6" id="nb-help-title"><?= e(t('notebooks.help_title')) ?></h2>
            <p class="mb-1"><code>con = lakehouse()</code> — <?= e(t('notebooks.help_lakehouse')) ?></p>
            <p class="mb-1"><code>con.sql("SELECT …").df()</code> — <?= e(t('notebooks.help_df')) ?></p>
            <p class="mb-0"><code>save_result("nombre", df)</code> — <?= e(t('notebooks.help_save')) ?></p>
        </section>
    </aside>

    <div class="col-12 col-lg-9">
        <section class="ec-card d-none" id="nb-editor" aria-labelledby="nb-editor-title">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
                <div class="flex-grow-1">
                    <h2 class="h6" id="nb-editor-title"><?= e(t('notebooks.editor')) ?></h2>
                    <label class="form-label small mb-1" for="nb-name"><?= e(t('notebooks.name')) ?></label>
                    <input class="form-control form-control-sm" id="nb-name" maxlength="80" autocomplete="off"<?= $canEdit ? '' : ' readonly' ?>>
                </div>
                <div class="d-flex flex-wrap gap-2">
<?php if ($canEdit): ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-nb-add="code"><i class="fa-solid fa-code" aria-hidden="true"></i> <?= e(t('notebooks.add_code')) ?></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-nb-add="markdown"><i class="fa-solid fa-paragraph" aria-hidden="true"></i> <?= e(t('notebooks.add_text')) ?></button>
                    <button class="btn btn-sm btn-outline-primary" type="button" id="nb-save"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> <?= e(t('notebooks.save')) ?></button>
<?php endif; ?>
                    <button class="btn btn-sm btn-primary" type="button" id="nb-run"<?= $canRun ? '' : ' disabled aria-disabled="true"' ?>
                            <?= $canRun ? '' : 'title="' . e(t('notebooks.run_disabled')) . '"' ?>><i class="fa-solid fa-play" aria-hidden="true"></i> <?= e(t('notebooks.run')) ?></button>
<?php if ($canEdit): ?>
                    <button class="btn btn-sm btn-outline-danger" type="button" id="nb-delete" aria-label="<?= e(t('notebooks.delete')) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
<?php endif; ?>
                </div>
            </div>
            <div id="nb-problems" class="alert alert-danger small d-none" role="alert"></div>
            <p class="small text-body-secondary" id="nb-status" aria-live="polite"></p>
            <ol class="ec-nb-cells list-unstyled mb-0" id="nb-cells"></ol>
            <section class="mt-3 d-none" id="nb-artifacts" aria-labelledby="nb-artifacts-title">
                <h3 class="h6" id="nb-artifacts-title"><?= e(t('notebooks.artifacts')) ?></h3>
                <div id="nb-artifacts-body"></div>
            </section>
        </section>

        <div class="ec-empty" id="nb-empty">
            <span class="ec-feature-icon"><i class="fa-solid fa-book-open" aria-hidden="true"></i></span>
            <h2 class="h5"><?= e(t('notebooks.empty_title')) ?></h2>
            <p class="text-body-secondary mb-0"><?= e(t('notebooks.empty_text')) ?></p>
        </div>
    </div>
</div>
