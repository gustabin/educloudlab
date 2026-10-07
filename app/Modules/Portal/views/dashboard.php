<?php
/**
 * @var \EduCloud\Core\Auth\AuthUser      $currentUser
 * @var \EduCloud\Core\Auth\TenantContext $tenantContext
 * @var string|null                       $tenantName
 * @var list<array<string, mixed>>        $recentWorkspaces
 * @var int                               $workspaceTotal
 * @var array<string, mixed>              $usage
 */
$storage = $usage['storage'];
$percent = $storage['quota_bytes'] > 0 ? min(100, (int) round(100 * $storage['used_bytes'] / $storage['quota_bytes'])) : 0;
$mb = static fn (int $bytes): string => fmt_number($bytes / 1048576);
?>
<div class="ec-page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(t('portal.dashboard.greeting', ['name' => $currentUser->displayName])) ?></h1>
        <p class="text-body-secondary mb-0"><?= e(t('portal.dashboard.tenant')) ?> <strong><?= e($tenantName) ?></strong></p>
    </div>
</div>

<section class="ec-card mb-4" aria-labelledby="usage-title">
    <h2 id="usage-title" class="h6"><?= e(t('usage.title')) ?></h2>
    <div class="small mb-1"><?= e(t('usage.storage', ['used' => $mb($storage['used_bytes']), 'quota' => $mb($storage['quota_bytes'])])) ?></div>
    <progress class="ec-usage<?= $percent >= 90 ? ' ec-usage-high' : '' ?> mb-1" max="100" value="<?= e($percent) ?>" aria-label="<?= e(t('usage.title')) ?>"><?= e($percent) ?>%</progress>
    <div class="small text-body-secondary"><?= e(t('usage.storage_detail', ['raw' => $mb($storage['raw_bytes']), 'lake' => $mb($storage['lakehouse_bytes'])])) ?></div>
    <div class="small text-body-secondary"><?= e(t('usage.month', ['jobs' => $usage['month']['jobs'], 'queries' => $usage['month']['queries']])) ?></div>
</section>

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
