<?php
/**
 * @var list<array<string, mixed>> $labs
 * @var bool $canStart
 */
?>
<div class="ec-page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(t('labs.title')) ?></h1>
        <p class="text-body-secondary mb-0"><?= e(t('labs.lead')) ?></p>
    </div>
</div>

<?php if ($labs === []): ?>
<div class="ec-empty">
    <span class="ec-feature-icon"><i class="fa-solid fa-flask" aria-hidden="true"></i></span>
    <h2 class="h5"><?= e(t('labs.empty_title')) ?></h2>
    <p class="text-body-secondary mb-0"><?= e(t('labs.empty_text')) ?></p>
</div>
<?php else: ?>
<div class="row g-3" id="lab-catalog">
<?php foreach ($labs as $lab):
    $mine = $lab['my_attempt'];
    $open = $mine !== null && $mine['open'];
    ?>
    <div class="col-12 col-lg-6">
        <article class="ec-card h-100 d-flex flex-column" aria-labelledby="lab-<?= e($lab['code']) ?>-title">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <span class="small text-body-secondary font-monospace"><?= e($lab['code']) ?></span>
                    <h2 class="h5 mb-0" id="lab-<?= e($lab['code']) ?>-title"><?= e($lab['title']) ?></h2>
                </div>
                <span class="ec-difficulty ec-difficulty-<?= e($lab['difficulty']) ?>"><?= e(t('labs.difficulty.' . $lab['difficulty'])) ?></span>
            </div>
            <p class="text-body-secondary small mb-2"><?= e($lab['summary']) ?></p>
            <ul class="list-inline small text-body-secondary mb-2">
                <li class="list-inline-item"><i class="fa-regular fa-clock" aria-hidden="true"></i> <?= e(t('labs.minutes', ['n' => $lab['estimated_minutes']])) ?></li>
                <li class="list-inline-item"><i class="fa-solid fa-list-check" aria-hidden="true"></i> <?= e(t('labs.tasks', ['n' => $lab['task_count']])) ?></li>
                <li class="list-inline-item"><i class="fa-solid fa-star" aria-hidden="true"></i> <?= e(t('labs.points', ['n' => $lab['max_score']])) ?></li>
<?php if ($lab['prerequisites'] !== []): ?>
                <li class="list-inline-item"><?= e(t('labs.prerequisites', ['list' => implode(', ', $lab['prerequisites'])])) ?></li>
<?php endif; ?>
            </ul>
            <details class="small mb-3">
                <summary><?= e(t('labs.objectives')) ?></summary>
                <ul class="mt-2 mb-0">
<?php foreach ($lab['objectives'] as $objective): ?>
                    <li><?= e($objective) ?></li>
<?php endforeach; ?>
                </ul>
            </details>
            <div class="mt-auto d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small">
<?php if ($mine !== null): ?>
                    <span class="ec-attempt ec-attempt-<?= e($mine['status']) ?>"><?= e(t('labs.state.' . $mine['status'])) ?></span>
                    <span class="text-body-secondary ms-1"><?= e(t('labs.best', ['score' => fmt_number($mine['best_score']), 'max' => $lab['max_score']])) ?></span>
<?php else: ?>
                    <span class="text-body-secondary"><?= e(t('labs.not_started')) ?></span>
<?php endif; ?>
                </span>
<?php if ($open): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(url('/app/lab-attempts/' . $mine['id'])) ?>"><?= e(t('labs.continue')) ?></a>
<?php elseif ($canStart): ?>
                <button class="btn btn-primary btn-sm" type="button" data-lab-start="<?= e($lab['code']) ?>">
                    <?= e($mine === null ? t('labs.start') : t('labs.restart')) ?>
                </button>
<?php endif; ?>
            </div>
        </article>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
