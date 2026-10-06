<?php
/**
 * @var \EduCloud\Core\Auth\AuthUser          $currentUser
 * @var \EduCloud\Core\Auth\TenantContext     $tenantContext
 * @var string|null                           $tenantName
 */
$roles = ['org_admin' => t('role.org_admin'), 'instructor' => t('role.instructor'), 'student' => t('role.student'), 'read_only' => t('role.read_only')];
?>
<section class="container py-5">
    <h1 class="h3 mb-1"><?= e(t('portal.dashboard.greeting', ['name' => $currentUser->displayName])) ?></h1>
    <p class="text-body-secondary mb-4">
        <?= e(t('portal.dashboard.tenant')) ?> <strong><?= e($tenantName) ?></strong>
        · <?= e(t('portal.dashboard.role')) ?> <span class="badge text-bg-light border"><?= e($roles[$tenantContext->role] ?? $tenantContext->role) ?></span>
    </p>
    <div class="ec-empty">
        <span class="ec-feature-icon"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span>
        <h2 class="h5"><?= e(t('portal.dashboard.empty_title')) ?></h2>
        <p class="text-body-secondary mb-0"><?= e(t('portal.dashboard.empty_text')) ?></p>
    </div>
</section>
