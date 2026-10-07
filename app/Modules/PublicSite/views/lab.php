<?php /** @var array<string, mixed> $lab */ ?>
<article class="container py-5" aria-labelledby="public-lab-title">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('/labs')) ?>"><?= e(t('public.labs.title')) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($lab['code']) ?></li>
        </ol>
    </nav>
    <h1 class="h2" id="public-lab-title"><?= e($lab['title']) ?></h1>
    <p class="lead text-body-secondary"><?= e($lab['summary']) ?></p>
    <p class="small">
        <span class="ec-difficulty"><?= e(t('labs.difficulty.' . $lab['difficulty'])) ?></span>
        <span class="ms-2"><?= e(t('labs.minutes', ['n' => $lab['estimated_minutes']])) ?> · <?= e(t('labs.points', ['n' => fmt_number($lab['max_score'])])) ?></span>
<?php if ($lab['prerequisites'] !== []): ?>
        <span class="ms-2"><?= e(t('labs.prerequisites', ['list' => implode(', ', $lab['prerequisites'])])) ?></span>
<?php endif; ?>
    </p>
    <div class="row g-4 mt-1">
        <div class="col-12 col-lg-6">
            <h2 class="h5"><?= e(t('labs.objectives')) ?></h2>
            <ul>
<?php foreach ($lab['objectives'] as $objective): ?>
                <li><?= e($objective) ?></li>
<?php endforeach; ?>
            </ul>
        </div>
        <div class="col-12 col-lg-6">
            <h2 class="h5"><?= e(t('public.lab.tasks')) ?></h2>
            <ol>
<?php foreach ($lab['tasks'] as $task): ?>
                <li><?= e($task['title']) ?> <span class="text-body-secondary small">(<?= e(t('labs.points', ['n' => $task['points']])) ?>)</span></li>
<?php endforeach; ?>
            </ol>
        </div>
    </div>
    <p class="text-body-secondary small"><?= e(t('public.lab.grading')) ?></p>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?= e(url('/app/labs')) ?>"><?= e(t('public.lab.start')) ?></a>
        <a class="btn btn-outline-primary" href="<?= e(url('/register')) ?>"><?= e(t('nav.register')) ?></a>
    </div>
</article>
