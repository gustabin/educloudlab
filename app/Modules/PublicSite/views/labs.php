<?php /** @var list<array<string, mixed>> $labs */ ?>
<section class="container py-5" aria-labelledby="public-labs-title">
    <h1 class="h2 mb-2" id="public-labs-title"><?= e(t('public.labs.title')) ?></h1>
    <p class="lead text-body-secondary mb-4"><?= e(t('public.labs.lead')) ?></p>
<?php if ($labs === []): ?>
    <p class="text-body-secondary"><?= e(t('labs.empty_text')) ?></p>
<?php else: ?>
    <div class="row g-4">
<?php foreach ($labs as $lab): ?>
        <div class="col-12 col-md-6">
            <article class="ec-card h-100">
                <span class="small text-body-secondary font-monospace"><?= e($lab['code']) ?></span>
                <h2 class="h5"><a href="<?= e(url('/labs/' . $lab['slug'])) ?>"><?= e($lab['title']) ?></a></h2>
                <p class="text-body-secondary small mb-2"><?= e($lab['summary']) ?></p>
                <p class="small mb-0">
                    <span class="ec-difficulty"><?= e(t('labs.difficulty.' . $lab['difficulty'])) ?></span>
                    <span class="ms-2"><?= e(t('labs.minutes', ['n' => $lab['estimated_minutes']])) ?> · <?= e(t('labs.tasks', ['n' => count($lab['tasks'])])) ?></span>
                </p>
            </article>
        </div>
<?php endforeach; ?>
    </div>
<?php endif; ?>
    <p class="mt-4 mb-0"><a href="<?= e(url('/courses')) ?>"><?= e(t('public.courses.link')) ?></a></p>
</section>
