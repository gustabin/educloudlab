<?php
$features = [
    ['icon' => 'fa-layer-group', 'key' => 'workspaces'],
    ['icon' => 'fa-database', 'key' => 'lakehouse'],
    ['icon' => 'fa-terminal', 'key' => 'sql'],
    ['icon' => 'fa-flask', 'key' => 'labs'],
];
?>
<section class="ec-hero">
    <div class="container">
        <h1 class="display-5 mb-3"><?= e(t('home.hero.title')) ?></h1>
        <p class="lead mb-4"><?= e(t('home.hero.lead')) ?></p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary btn-lg" href="<?= e(url('/register')) ?>"><?= e(t('home.hero.cta')) ?></a>
            <a class="btn btn-outline-primary btn-lg" href="<?= e(url('/labs')) ?>"><?= e(t('home.hero.secondary')) ?></a>
        </div>
    </div>
</section>

<section class="container py-5" aria-labelledby="features-title">
    <h2 id="features-title" class="h3 mb-4"><?= e(t('home.features.title')) ?></h2>
    <div class="row g-4">
<?php foreach ($features as $f): ?>
        <div class="col-12 col-sm-6 col-lg-3">
            <article class="ec-feature">
                <span class="ec-feature-icon"><i class="fa-solid <?= e($f['icon']) ?>" aria-hidden="true"></i></span>
                <h3 class="h5"><?= e(t('home.feature.' . $f['key'] . '.title')) ?></h3>
                <p class="mb-0 text-body-secondary"><?= e(t('home.feature.' . $f['key'] . '.text')) ?></p>
            </article>
        </div>
<?php endforeach; ?>
    </div>
</section>
