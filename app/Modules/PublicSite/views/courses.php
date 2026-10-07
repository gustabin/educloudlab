<?php /** @var list<array<string, mixed>> $courses */ ?>
<section class="container py-5" aria-labelledby="public-courses-title">
    <h1 class="h2 mb-2" id="public-courses-title"><?= e(t('public.courses.title')) ?></h1>
    <p class="lead text-body-secondary mb-4"><?= e(t('public.courses.lead')) ?></p>
<?php if ($courses === []): ?>
    <p class="text-body-secondary"><?= e(t('public.courses.empty')) ?> <a href="<?= e(url('/labs')) ?>"><?= e(t('public.courses.see_labs')) ?></a></p>
<?php else: ?>
    <div class="list-group">
<?php foreach ($courses as $course): ?>
        <a class="list-group-item list-group-item-action py-3" href="<?= e(url('/courses/' . $course['slug'])) ?>">
            <span class="small text-body-secondary font-monospace"><?= e($course['code']) ?></span>
            <span class="d-block fw-semibold"><?= e($course['title']) ?></span>
            <span class="small text-body-secondary"><?= e($course['organization']) ?> · <?= e(t('courses.labs_count', ['n' => $course['lab_count']])) ?></span>
        </a>
<?php endforeach; ?>
    </div>
<?php endif; ?>
</section>
