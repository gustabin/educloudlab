<?php
/**
 * @var array<string, mixed> $workspace
 * @var bool $hasLakehouse
 * @var bool $canExecute
 * @var bool $canCreate
 * @var int  $maxRows
 */
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces')) ?>"><?= e(t('ws.title')) ?></a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces/' . $workspace['id'])) ?>"><?= e($workspace['name']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e(t('sql.title')) ?></li>
    </ol>
</nav>

<div class="ec-page-header mb-3">
    <div>
        <h1 class="h3 mb-1"><?= e(t('sql.title')) ?></h1>
        <p class="text-body-secondary mb-0 small"><?= e(t('sql.lead', ['n' => $maxRows])) ?></p>
    </div>
</div>

<?php if (!$hasLakehouse): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
    <span><?= e(t('sql.no_lakehouse')) ?></span>
    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('/app/workspaces/' . $workspace['id'])) ?>"><?= e(t('sql.go_workspace')) ?></a>
</div>
<?php endif; ?>

<div class="row g-3" id="sql-lab" data-workspace-id="<?= e($workspace['id']) ?>"
     data-can-execute="<?= $canExecute && $hasLakehouse ? '1' : '0' ?>" data-can-create="<?= $canCreate && $hasLakehouse ? '1' : '0' ?>">
    <aside class="col-12 col-lg-3">
        <section class="ec-card ec-catalog" aria-labelledby="sql-catalog-title">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0" id="sql-catalog-title"><?= e(t('sql.catalog')) ?></h2>
                <button class="btn btn-sm btn-link p-0" type="button" id="sql-catalog-refresh" aria-label="<?= e(t('sql.refresh_catalog')) ?>">
                    <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                </button>
            </div>
            <div id="sql-catalog" aria-busy="true" aria-live="polite"><div class="ec-skeleton ec-skeleton-sm" aria-hidden="true"></div></div>
            <p class="small text-body-secondary mt-2 mb-0"><?= e(t('sql.catalog_hint')) ?></p>
        </section>
    </aside>

    <div class="col-12 col-lg-9">
        <section class="ec-card mb-3" aria-labelledby="sql-editor-label">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <label class="h6 mb-0" id="sql-editor-label" for="sql-editor"><?= e(t('sql.editor')) ?></label>
                <div class="d-flex gap-2">
<?php if ($canCreate && $hasLakehouse): ?>
                    <button class="btn btn-sm btn-outline-primary" type="button" id="sql-save-table" data-bs-toggle="modal" data-bs-target="#sql-save-modal">
                        <i class="fa-solid fa-table" aria-hidden="true"></i> <?= e(t('sql.save_table')) ?>
                    </button>
<?php endif; ?>
                    <button class="btn btn-sm btn-primary" type="button" id="sql-run"<?= $canExecute && $hasLakehouse ? '' : ' disabled' ?>>
                        <i class="fa-solid fa-play" aria-hidden="true"></i> <?= e(t('sql.run')) ?> <kbd class="ms-1 d-none d-md-inline">Ctrl+Enter</kbd>
                    </button>
                </div>
            </div>
            <textarea id="sql-editor" class="form-control font-monospace" rows="8" spellcheck="false" aria-describedby="sql-editor-help"></textarea>
            <div id="sql-editor-help" class="form-text"><?= e(t('sql.editor_help')) ?></div>
        </section>

        <section class="ec-card" aria-labelledby="sql-results-title">
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="sql-results-tab" data-bs-toggle="tab" data-bs-target="#sql-results-pane" type="button" role="tab"
                            aria-controls="sql-results-pane" aria-selected="true"><span id="sql-results-title"><?= e(t('sql.results')) ?></span></button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sql-history-tab" data-bs-toggle="tab" data-bs-target="#sql-history-pane" type="button" role="tab"
                            aria-controls="sql-history-pane" aria-selected="false"><?= e(t('sql.history')) ?></button>
                </li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="sql-results-pane" role="tabpanel" aria-labelledby="sql-results-tab" tabindex="0">
                    <div id="sql-status" class="small text-body-secondary mb-2" aria-live="polite"><?= e(t('sql.results_empty')) ?></div>
                    <div id="sql-error" class="alert alert-danger d-none" role="alert"></div>
                    <div id="sql-results"></div>
                </div>
                <div class="tab-pane fade" id="sql-history-pane" role="tabpanel" aria-labelledby="sql-history-tab" tabindex="0">
                    <div id="sql-history" aria-live="polite"></div>
                </div>
            </div>
        </section>
    </div>
</div>

<?php if ($canCreate && $hasLakehouse): ?>
<div class="modal fade" id="sql-save-modal" tabindex="-1" aria-labelledby="sql-save-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="sql-save-form" novalidate>
            <div class="modal-header">
                <h2 class="modal-title h5" id="sql-save-title"><?= e(t('sql.save_table')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('nav.close')) ?>"></button>
            </div>
            <div class="modal-body">
                <p class="small text-body-secondary"><?= e(t('sql.save_lead')) ?></p>
                <div class="row g-2">
                    <div class="col-5">
                        <label class="form-label" for="sql-save-layer"><?= e(t('ds.col.layer')) ?></label>
                        <select class="form-select" id="sql-save-layer" name="layer">
                            <option value="silver">silver</option>
                            <option value="gold">gold</option>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label" for="sql-save-table-name"><?= e(t('ds.ingest_label')) ?></label>
                        <input class="form-control" id="sql-save-table-name" name="table" required maxlength="63" autocomplete="off" placeholder="customers_clean">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(t('sql.save_table')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
