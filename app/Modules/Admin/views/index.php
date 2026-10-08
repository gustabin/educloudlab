<?php /* Admin monitor: data is loaded by admin.js from /api/v1/admin/* and rendered with .text() only. */ ?>
<div class="ec-page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(t('admin.title')) ?></h1>
        <p class="text-body-secondary mb-0"><?= e(t('admin.lead')) ?></p>
    </div>
    <button class="btn btn-outline-secondary btn-sm" type="button" id="admin-refresh"><i class="fa-solid fa-rotate" aria-hidden="true"></i> <?= e(t('admin.refresh')) ?></button>
</div>

<div id="admin" aria-live="polite">
    <section class="row g-3 mb-4" id="admin-overview" aria-label="<?= e(t('admin.overview')) ?>" aria-busy="true">
<?php for ($i = 0; $i < 4; $i++): ?>
        <div class="col-6 col-lg-3"><div class="ec-card ec-skeleton ec-skeleton-sm" aria-hidden="true"></div></div>
<?php endfor; ?>
    </section>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="admin-jobs-tab" data-bs-toggle="tab" data-bs-target="#admin-jobs-pane" type="button" role="tab" aria-controls="admin-jobs-pane" aria-selected="true"><?= e(t('admin.jobs')) ?></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="admin-audit-tab" data-bs-toggle="tab" data-bs-target="#admin-audit-pane" type="button" role="tab" aria-controls="admin-audit-pane" aria-selected="false"><?= e(t('admin.audit')) ?></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="admin-users-tab" data-bs-toggle="tab" data-bs-target="#admin-users-pane" type="button" role="tab" aria-controls="admin-users-pane" aria-selected="false"><?= e(t('admin.users')) ?></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="admin-obs-tab" data-bs-toggle="tab" data-bs-target="#admin-obs-pane" type="button" role="tab" aria-controls="admin-obs-pane" aria-selected="false"><?= e(t('admin.observability')) ?></button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="admin-jobs-pane" role="tabpanel" aria-labelledby="admin-jobs-tab" tabindex="0">
            <form class="row g-2 mb-2" data-admin-filter="jobs">
                <div class="col-6 col-md-3">
                    <label class="visually-hidden" for="admin-jobs-status"><?= e(t('admin.filter_status')) ?></label>
                    <select class="form-select form-select-sm" id="admin-jobs-status" name="status">
                        <option value=""><?= e(t('admin.all_statuses')) ?></option>
<?php foreach (['queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out'] as $s): ?>
                        <option value="<?= e($s) ?>"><?= e($s) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="visually-hidden" for="admin-jobs-type"><?= e(t('admin.filter_type')) ?></label>
                    <select class="form-select form-select-sm" id="admin-jobs-type" name="type">
                        <option value=""><?= e(t('admin.all_types')) ?></option>
<?php foreach (explode(',', \EduCloud\Modules\Admin\AdminController::JOB_TYPES) as $s): ?>
                        <option value="<?= e($s) ?>"><?= e($s) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
            </form>
            <div data-admin-list="jobs"></div>
        </div>
        <div class="tab-pane fade" id="admin-audit-pane" role="tabpanel" aria-labelledby="admin-audit-tab" tabindex="0">
            <form class="row g-2 mb-2" data-admin-filter="audit">
                <div class="col-6 col-md-4">
                    <label class="visually-hidden" for="admin-audit-action"><?= e(t('admin.filter_action')) ?></label>
                    <input class="form-control form-control-sm" id="admin-audit-action" name="action" placeholder="<?= e(t('admin.filter_action')) ?>" maxlength="60" autocomplete="off">
                </div>
                <div class="col-6 col-md-3">
                    <label class="visually-hidden" for="admin-audit-outcome"><?= e(t('admin.filter_outcome')) ?></label>
                    <select class="form-select form-select-sm" id="admin-audit-outcome" name="outcome">
                        <option value=""><?= e(t('admin.all_outcomes')) ?></option>
                        <option value="success">success</option>
                        <option value="failure">failure</option>
                        <option value="denied">denied</option>
                    </select>
                </div>
            </form>
            <div data-admin-list="audit"></div>
        </div>
        <div class="tab-pane fade" id="admin-users-pane" role="tabpanel" aria-labelledby="admin-users-tab" tabindex="0">
            <form class="row g-2 mb-2" data-admin-filter="users">
                <div class="col-6 col-md-4">
                    <label class="visually-hidden" for="admin-users-q"><?= e(t('admin.search_users')) ?></label>
                    <input class="form-control form-control-sm" id="admin-users-q" name="q" type="search" placeholder="<?= e(t('admin.search_users')) ?>" maxlength="100" autocomplete="off">
                </div>
                <div class="col-6 col-md-3">
                    <label class="visually-hidden" for="admin-users-status"><?= e(t('admin.filter_status')) ?></label>
                    <select class="form-select form-select-sm" id="admin-users-status" name="status">
                        <option value=""><?= e(t('admin.all_statuses')) ?></option>
<?php foreach (['active', 'pending', 'locked', 'disabled'] as $s): ?>
                        <option value="<?= e($s) ?>"><?= e($s) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
            </form>
            <div data-admin-list="users"></div>
        </div>
        <div class="tab-pane fade" id="admin-obs-pane" role="tabpanel" aria-labelledby="admin-obs-tab" tabindex="0">
            <h2 class="h5"><?= e(t('obs.components')) ?></h2>
            <p class="small mb-2" id="obs-overall"></p>
            <div class="row g-2 mb-4" id="obs-health" aria-busy="true"></div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <label class="small" for="obs-window"><?= e(t('obs.window_label')) ?></label>
                <select class="form-select form-select-sm w-auto" id="obs-window">
<?php foreach (['1h', '24h', '7d'] as $w): ?>
                    <option value="<?= e($w) ?>"<?= $w === '24h' ? ' selected' : '' ?>><?= e(t('obs.window.' . $w)) ?></option>
<?php endforeach; ?>
                </select>
            </div>
            <div id="obs-metrics" aria-busy="true"></div>

            <h2 class="h5 mt-4"><?= e(t('obs.logs')) ?></h2>
            <p class="small text-body-secondary"><?= e(t('obs.logs_help')) ?></p>
            <form class="row g-2 mb-2" id="obs-logs-form" novalidate>
                <div class="col-12 col-md-5">
                    <label class="visually-hidden" for="obs-request-id"><?= e(t('obs.request_id')) ?></label>
                    <input class="form-control form-control-sm font-monospace" id="obs-request-id" name="request_id" placeholder="<?= e(t('obs.request_id')) ?>" maxlength="26" autocomplete="off" spellcheck="false">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> <?= e(t('obs.search')) ?></button>
                </div>
            </form>
            <div id="obs-logs" aria-busy="false"></div>
        </div>
    </div>
</div>
