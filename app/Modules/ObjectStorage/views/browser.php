<?php
/**
 * @var array<string, mixed> $resource
 * @var bool $canEdit
 * @var int  $uploadMaxBytes
 */
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces')) ?>"><?= e(t('ws.title')) ?></a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('/app/workspaces/' . $resource['workspace_id'])) ?>"><?= e(t('storage.workspace')) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($resource['name']) ?></li>
    </ol>
</nav>

<div class="ec-page-header mb-3">
    <div>
        <h1 class="h3 mb-1"><i class="fa-solid fa-box-archive text-body-secondary me-1" aria-hidden="true"></i><?= e($resource['name']) ?></h1>
        <p class="text-body-secondary mb-0 small"><?= e(t('storage.lead')) ?></p>
    </div>
</div>

<div class="row g-3" id="storage" data-resource-id="<?= e($resource['id']) ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>">
    <aside class="col-12 col-lg-4">
        <section class="ec-card" aria-labelledby="st-containers-title">
            <h2 class="h6" id="st-containers-title"><?= e(t('storage.containers')) ?></h2>
            <div id="st-containers" aria-busy="true" aria-live="polite"><div class="ec-skeleton ec-skeleton-sm" aria-hidden="true"></div></div>
<?php if ($canEdit): ?>
            <form class="mt-3" id="st-container-form" novalidate>
                <label class="form-label small" for="st-container-name"><?= e(t('storage.new_container')) ?></label>
                <div class="input-group input-group-sm">
                    <input class="form-control" id="st-container-name" name="name" maxlength="63" placeholder="datos-crudos" autocomplete="off" aria-describedby="st-container-help">
                    <button class="btn btn-outline-primary" type="submit"><?= e(t('storage.create')) ?></button>
                </div>
                <div class="form-text" id="st-container-help"><?= e(t('storage.container_help')) ?></div>
            </form>
<?php endif; ?>
        </section>
    </aside>

    <div class="col-12 col-lg-8">
        <div class="ec-empty" id="st-empty">
            <span class="ec-feature-icon"><i class="fa-solid fa-box-open" aria-hidden="true"></i></span>
            <p class="text-body-secondary mb-0"><?= e(t('storage.pick_container')) ?></p>
        </div>
        <section class="ec-card d-none" id="st-container" aria-labelledby="st-container-title">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h2 class="h5 mb-0 font-monospace" id="st-container-title"></h2>
<?php if ($canEdit): ?>
                <button class="btn btn-sm btn-outline-danger" type="button" id="st-container-delete"><?= e(t('storage.delete_container')) ?></button>
<?php endif; ?>
            </div>
            <p class="small text-body-secondary" id="st-container-summary" aria-live="polite"></p>

<?php if ($canEdit): ?>
            <form class="row g-2 align-items-end mb-3" id="st-lifecycle-form" novalidate>
                <div class="col-12"><span class="small fw-semibold"><?= e(t('storage.lifecycle')) ?></span></div>
                <div class="col-6 col-md-4">
                    <label class="form-label small" for="st-archive-days"><?= e(t('storage.archive_after')) ?></label>
                    <input class="form-control form-control-sm" type="number" min="1" max="3650" id="st-archive-days" name="archive_after_days">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label small" for="st-delete-days"><?= e(t('storage.delete_after')) ?></label>
                    <input class="form-control form-control-sm" type="number" min="1" max="3650" id="st-delete-days" name="delete_after_days">
                </div>
                <div class="col-12 col-md-4"><button class="btn btn-sm btn-outline-primary w-100" type="submit"><?= e(t('storage.save_lifecycle')) ?></button></div>
            </form>

            <form class="row g-2 align-items-end mb-3" id="st-upload-form" novalidate>
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="st-key"><?= e(t('storage.key')) ?></label>
                    <input class="form-control form-control-sm font-monospace" id="st-key" name="key" maxlength="255" placeholder="ventas/2025/enero.csv" autocomplete="off">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="st-file"><?= e(t('storage.file')) ?></label>
                    <input class="form-control form-control-sm" type="file" id="st-file" name="file" accept=".csv,.json,.jsonl,.ndjson,.parquet,.txt,.md" aria-describedby="st-file-help">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="st-tier"><?= e(t('storage.tier')) ?></label>
                    <select class="form-select form-select-sm" id="st-tier" name="tier">
                        <option value="hot">hot</option><option value="cool">cool</option><option value="archive">archive</option>
                    </select>
                </div>
                <div class="col-6 col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit"><i class="fa-solid fa-upload" aria-hidden="true"></i> <?= e(t('storage.upload')) ?></button></div>
                <div class="col-12">
                    <label class="form-label small" for="st-metadata"><?= e(t('storage.metadata')) ?></label>
                    <input class="form-control form-control-sm font-monospace" id="st-metadata" name="metadata" placeholder='{"origen": "erp", "confidencial": "no"}' autocomplete="off">
                    <div class="form-text" id="st-file-help"><?= e(t('storage.file_help', ['mb' => (int) round($uploadMaxBytes / 1048576)])) ?></div>
                </div>
            </form>
<?php endif; ?>

            <label class="visually-hidden" for="st-prefix"><?= e(t('storage.prefix')) ?></label>
            <input class="form-control form-control-sm mb-2" id="st-prefix" type="search" placeholder="<?= e(t('storage.prefix')) ?>" maxlength="255" autocomplete="off">
            <div id="st-objects" aria-live="polite"></div>
        </section>
    </div>
</div>
