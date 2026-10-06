<?php
/**
 * @var \EduCloud\Core\Auth\AuthUser      $currentUser
 * @var \EduCloud\Core\Auth\TenantContext $tenantContext
 * @var string|null                       $tenantName
 * @var list<array<string, mixed>>        $recentWorkspaces
 * @var int                               $workspaceTotal
 */
?>
<div class="ec-page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(t('portal.dashboard.greeting', ['name' => $currentUser->displayName])) ?></h1>
        <p class="text-body-secondary mb-0"><?= e(t('portal.dashboard.tenant')) ?> <strong><?= e($tenantName) ?></strong></p>
    </div>
</div>

<section aria-labelledby="recent-title">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 id="recent-title" class="h5 mb-0"><?= e(t('portal.dashboard.recent')) ?></h2>
        <a class="small" href="<?= e(url('/app/workspaces')) ?>"><?= e(t('portal.dashboard.all_workspaces', ['n' => $workspaceTotal])) ?></a>
    </div>
<?php if ($recentWorkspaces === []): ?>
    <div class="ec-empty">
        <span class="ec-feature-icon"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span>
        <h3 class="h5"><?= e(t('portal.dashboard.empty_title')) ?></h3>
        <p class="text-body-secondary"><?= e(t('ws.empty_text')) ?></p>
        <a class="btn btn-primary" href="<?= e(url('/app/workspaces')) ?>"><?= e(t('ws.create')) ?></a>
    </div>
<?php else: ?>
    <div class="list-group">
<?php foreach ($recentWorkspaces as $ws): ?>
        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
           href="<?= e(url('/app/workspaces/' . $ws['id'])) ?>">
            <span><i class="fa-solid fa-layer-group text-body-secondary me-2" aria-hidden="true"></i><?= e($ws['name']) ?></span>
            <span class="badge text-bg-light border"><?= e(t('ws.resources_count', ['n' => $ws['resource_count']])) ?></span>
        </a>
<?php endforeach; ?>
    </div>
<?php endif; ?>
</section>
