<?php
/**
 * @var array<string, mixed>       $course
 * @var list<array<string, mixed>> $labs
 */
?>
<article class="container py-5" aria-labelledby="public-course-title">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('/courses')) ?>"><?= e(t('public.courses.title')) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($course['code']) ?></li>
        </ol>
    </nav>
    <h1 class="h2" id="public-course-title"><?= e($course['title']) ?></h1>
    <p class="text-body-secondary"><?= e($course['organization']) ?></p>
<?php if ($course['description'] !== null): ?>
    <p><?= nl2br(e($course['description'])) ?></p>
<?php endif; ?>
    <h2 class="h5 mt-4"><?= e(t('courses.labs')) ?></h2>
<?php if ($labs === []): ?>
    <p class="text-body-secondary"><?= e(t('courses.labs_empty')) ?></p>
<?php else: ?>
    <ul>
<?php foreach ($labs as $lab): ?>
        <li><a href="<?= e(url('/labs/' . $lab['slug'])) ?>"><?= e($lab['code'] . ' · ' . $lab['title']) ?></a>
            <span class="small text-body-secondary">(<?= e(t('labs.difficulty.' . $lab['difficulty'])) ?>, <?= e(t('labs.minutes', ['n' => $lab['estimated_minutes']])) ?>)</span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <div class="alert alert-info mt-4"><?= e(t('public.course.join')) ?></div>
    <a class="btn btn-primary" href="<?= e(url('/app/courses')) ?>"><?= e(t('public.course.join_cta')) ?></a>
</article>
