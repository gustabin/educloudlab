<?php /** @var list<array<string, mixed>> $workspaces */ ?>
<div class="ec-page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(t('sql.title')) ?></h1>
        <p class="text-body-secondary mb-0"><?= e(t('sql.chooser_lead')) ?></p>
    </div>
</div>
<?php if ($workspaces === []): ?>
<div class="ec-empty">
    <span class="ec-feature-icon"><i class="fa-solid fa-terminal" aria-hidden="true"></i></span>
    <h2 class="h5"><?= e(t('portal.dashboard.empty_title')) ?></h2>
    <p class="text-body-secondary"><?= e(t('sql.chooser_empty')) ?></p>
    <a class="btn btn-primary" href="<?= e(url('/app/workspaces')) ?>"><?= e(t('ws.create')) ?></a>
</div>
<?php else: ?>
<div class="list-group">
<?php foreach ($workspaces as $ws): ?>
    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
       href="<?= e(url('/app/workspaces/' . $ws['id'] . '/sql')) ?>">
        <span><i class="fa-solid fa-layer-group text-body-secondary me-2" aria-hidden="true"></i><?= e($ws['name']) ?></span>
        <span class="small text-primary"><?= e(t('sql.open')) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
    </a>
<?php endforeach; ?>
</div>
<?php endif; ?>
