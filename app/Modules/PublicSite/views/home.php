<?php
/**
 * Public home (docs/ux/screens/public-home.md). Server-rendered, no JavaScript.
 * @var list<array<string, mixed>> $labs  published labs (PublicSiteController::presentLab)
 * @var array{labs: int, exercises: int, hours: int} $stats
 */
$features = [
    ['key' => 'workspaces', 'icon' => 'fa-layer-group', 'chips' => ['Roles', 'Ciclo de vida']],
    ['key' => 'storage', 'icon' => 'fa-box-archive', 'chips' => ['Contenedores', 'Archivado']],
    ['key' => 'lakehouse', 'icon' => 'fa-database', 'chips' => ['JSON', 'Parquet']],
    ['key' => 'sql', 'icon' => 'fa-terminal', 'chips' => ['CTE', 'Ventanas']],
    ['key' => 'pipelines', 'icon' => 'fa-diagram-project', 'chips' => ['Calidad', 'Linaje']],
    ['key' => 'warehouse', 'icon' => 'fa-cubes', 'chips' => ['Estrella', 'SCD 1']],
    ['key' => 'analytics', 'icon' => 'fa-chart-column', 'chips' => ['KPI', 'Filtros']],
    ['key' => 'notebooks', 'icon' => 'fa-book-open', 'chips' => ['pandas', 'DuckDB']],
    ['key' => 'labs', 'icon' => 'fa-flask', 'chips' => ['Pistas', 'Mejor nota']],
];
$flow = [
    ['key' => 'raw', 'layer' => 'raw'],
    ['key' => 'bronze', 'layer' => 'bronze'],
    ['key' => 'silver', 'layer' => 'silver'],
    ['key' => 'gold', 'layer' => 'gold'],
    ['key' => 'model', 'layer' => 'model'],
    ['key' => 'dashboard', 'layer' => 'model'],
];
$audiences = [
    ['key' => 'students', 'icon' => 'fa-user-graduate', 'items' => 4],
    ['key' => 'teachers', 'icon' => 'fa-chalkboard-user', 'items' => 4],
    ['key' => 'open', 'icon' => 'fa-code-branch', 'items' => 3],
];
$secure = [
    ['key' => 'tenants', 'icon' => 'fa-building-shield'],
    ['key' => 'sql', 'icon' => 'fa-shield-halved'],
    ['key' => 'notebooks', 'icon' => 'fa-box'],
    ['key' => 'data', 'icon' => 'fa-user-secret'],
];
$lastCode = $labs === [] ? '' : (string) $labs[count($labs) - 1]['code'];
?>
<section class="ec-hero ec-home-hero" aria-labelledby="home-title">
    <div class="container">
        <div class="row align-items-center g-4 g-lg-5">
            <div class="col-12 col-lg-6">
                <p class="ec-home-eyebrow"><?= e(t('home.hero.eyebrow')) ?></p>
                <h1 id="home-title" class="display-5 mb-3"><?= e(t('home.hero.title')) ?></h1>
                <p class="lead mb-4"><?= e(t('home.hero.lead')) ?></p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a class="btn btn-primary btn-lg" href="<?= e(url('/register')) ?>"><?= e(t('home.hero.cta')) ?></a>
                    <a class="btn btn-outline-primary btn-lg" href="<?= e(url('/labs')) ?>"><?= e(t('home.hero.secondary')) ?></a>
                </div>
                <ul class="ec-home-trust">
<?php foreach (['free', 'open', 'grading', 'synthetic'] as $k): ?>
                    <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= e(t('home.trust.' . $k)) ?></li>
<?php endforeach; ?>
                </ul>
            </div>
            <div class="col-12 col-lg-6">
                <p class="visually-hidden"><?= e(t('home.mock.description')) ?></p>
                <div class="ec-mock" aria-hidden="true">
                    <div class="ec-mock-bar">
                        <span class="ec-mock-dot"></span><span class="ec-mock-dot"></span><span class="ec-mock-dot"></span>
                        <span class="ec-mock-title"><?= e(t('home.mock.title')) ?></span>
                    </div>
                    <pre class="ec-mock-code"><code><span class="ec-tok-kw">SELECT</span> region, <span class="ec-tok-fn">sum</span>(total) <span class="ec-tok-kw">AS</span> ingresos
<span class="ec-tok-kw">FROM</span> gold.ventas
<span class="ec-tok-kw">GROUP BY</span> region
<span class="ec-tok-kw">ORDER BY</span> ingresos <span class="ec-tok-kw">DESC</span></code></pre>
                    <table class="ec-mock-table">
                        <thead><tr><th>region</th><th>ingresos</th><th></th></tr></thead>
                        <tbody>
<?php foreach ([['Norte', '12.430 €', 100], ['Centro', '10.915 €', 88], ['Sur', '9.870 €', 79]] as [$region, $amount, $width]): ?>
                            <tr>
                                <td><?= e($region) ?></td>
                                <td class="text-end"><?= e($amount) ?></td>
                                <td class="ec-mock-barcell"><span class="ec-mock-bar-fill ec-w-<?= (int) $width ?>"></span></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="ec-mock-pass"><i class="fa-solid fa-circle-check"></i> <?= e(t('home.mock.passed')) ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="ec-home-section ec-home-stats" aria-labelledby="stats-title">
    <div class="container">
        <h2 id="stats-title" class="visually-hidden"><?= e(t('home.stats.title')) ?></h2>
<?php if ($stats['labs'] > 0): ?>
        <ul class="row g-4 mb-0 list-unstyled text-center">
<?php foreach (($stats['labs'] > 0 ? ['labs', 'exercises', 'hours'] : []) as $k): ?>
            <li class="col-12 col-sm-4"><span class="ec-home-stat"><?= (int) $stats[$k] ?></span> <?= e(t('home.stats.' . $k)) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </div>
</section>

<section class="ec-home-section ec-home-alt" aria-labelledby="flow-title">
    <div class="container">
        <h2 id="flow-title" class="h3"><?= e(t('home.flow.title')) ?></h2>
        <p class="ec-home-lead"><?= e(t('home.flow.lead')) ?></p>
        <ol class="ec-flow">
            <li class="ec-flow-step ec-flow-files">
                <i class="fa-solid fa-file-csv" aria-hidden="true"></i>
                <span class="ec-flow-name"><?= e(t('home.flow.files')) ?></span>
            </li>
<?php foreach ($flow as $step): ?>
            <li class="ec-flow-step ec-flow-<?= e($step['layer']) ?>">
                <span class="ec-flow-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                <span class="ec-flow-name"><?= e(t('home.flow.' . $step['key'])) ?></span>
                <span class="ec-flow-text"><?= e(t('home.flow.' . $step['key'] . '_text')) ?></span>
            </li>
<?php endforeach; ?>
        </ol>
        <p class="ec-home-note"><i class="fa-solid fa-diagram-project" aria-hidden="true"></i> <?= e(t('home.flow.note')) ?></p>
    </div>
</section>

<section class="ec-home-section" aria-labelledby="features-title">
    <div class="container">
        <h2 id="features-title" class="h3"><?= e(t('home.features.title')) ?></h2>
        <p class="ec-home-lead"><?= e(t('home.features.lead')) ?></p>
        <div class="row g-3">
<?php foreach ($features as $f): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <article class="ec-feature ec-home-feature">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <span class="ec-feature-icon mb-0"><i class="fa-solid <?= e($f['icon']) ?>" aria-hidden="true"></i></span>
                        <h3 class="h6 mb-0"><?= e(t('home.feature.' . $f['key'] . '.title')) ?></h3>
                    </div>
                    <p class="text-body-secondary small mb-2"><?= e(t('home.feature.' . $f['key'] . '.text')) ?></p>
                    <ul class="ec-chips">
<?php foreach ($f['chips'] as $chip): ?>
                        <li><?= e($chip) ?></li>
<?php endforeach; ?>
                    </ul>
                </article>
            </div>
<?php endforeach; ?>
        </div>
    </div>
</section>

<section class="ec-home-section ec-home-alt" aria-labelledby="path-title">
    <div class="container">
        <h2 id="path-title" class="h3"><?= e(t('home.path.title')) ?></h2>
        <p class="ec-home-lead"><?= e(t('home.path.lead')) ?></p>
<?php if ($labs === []): ?>
        <p><a href="<?= e(url('/labs')) ?>"><?= e(t('home.path.empty')) ?></a></p>
<?php else: ?>
        <ol class="ec-path">
<?php foreach ($labs as $lab): ?>
            <li>
                <span class="ec-path-code"><?= e($lab['code']) ?></span>
                <a href="<?= e(url('/labs/' . $lab['slug'])) ?>"><?= e($lab['title']) ?></a>
                <span class="ec-path-meta">
<?php if ($lab['code'] === $lastCode && count($labs) > 1): ?>
                    <span class="ec-path-capstone"><?= e(t('home.path.capstone')) ?></span>
<?php endif; ?>
                    <span class="ec-difficulty"><?= e(t('labs.difficulty.' . $lab['difficulty'])) ?></span>
                    <span><?= e(t('home.path.minutes', ['n' => (string) $lab['estimated_minutes']])) ?></span>
                </span>
            </li>
<?php endforeach; ?>
        </ol>
<?php endif; ?>
        <p class="ec-home-note"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= e(t('home.path.grading')) ?></p>
        <p class="mt-3 mb-0"><a class="fw-semibold" href="<?= e(url('/labs')) ?>"><?= e(t('home.path.all')) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></p>
    </div>
</section>

<section class="ec-home-section" aria-labelledby="audience-title">
    <div class="container">
        <h2 id="audience-title" class="h3 mb-4"><?= e(t('home.audience.title')) ?></h2>
        <div class="row g-4">
<?php foreach ($audiences as $a): ?>
            <div class="col-12 col-lg-4">
                <article class="ec-home-audience">
                    <h3 class="h5"><i class="fa-solid <?= e($a['icon']) ?> text-primary" aria-hidden="true"></i> <?= e(t('home.audience.' . $a['key'] . '.title')) ?></h3>
                    <ul class="ec-checklist">
<?php for ($i = 1; $i <= $a['items']; $i++): ?>
                        <li><?= e(t('home.audience.' . $a['key'] . '.' . $i)) ?></li>
<?php endfor; ?>
                    </ul>
<?php if ($a['key'] === 'open'): ?>
                    <a class="btn btn-outline-primary btn-sm" href="https://github.com/gustabin/educloudlab" rel="noopener">
                        <i class="fa-brands fa-github" aria-hidden="true"></i> <?= e(t('home.audience.open.cta')) ?>
                    </a>
<?php endif; ?>
                </article>
            </div>
<?php endforeach; ?>
        </div>
    </div>
</section>

<section class="ec-home-section ec-home-alt" aria-labelledby="secure-title">
    <div class="container">
        <h2 id="secure-title" class="h3 mb-4"><?= e(t('home.secure.title')) ?></h2>
        <ul class="row g-3 list-unstyled mb-0">
<?php foreach ($secure as $s): ?>
            <li class="col-12 col-sm-6 col-lg-3 ec-home-secure">
                <i class="fa-solid <?= e($s['icon']) ?>" aria-hidden="true"></i>
                <span><?= e(t('home.secure.' . $s['key'])) ?></span>
            </li>
<?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="ec-home-section" aria-labelledby="faq-title">
    <div class="container">
        <h2 id="faq-title" class="h3 mb-3"><?= e(t('home.faq.title')) ?></h2>
        <div class="ec-home-narrow">
<?php foreach (['free', 'azure', 'class', 'install'] as $q): ?>
            <details class="ec-faq">
                <summary><?= e(t('home.faq.' . $q . '.q')) ?></summary>
                <p class="mb-0"><?= e(t('home.faq.' . $q . '.a')) ?></p>
            </details>
<?php endforeach; ?>
        </div>
    </div>
</section>

<section class="ec-home-cta" aria-labelledby="cta-title">
    <div class="container text-center">
        <h2 id="cta-title" class="h3"><?= e(t('home.cta.title')) ?></h2>
        <p class="ec-home-lead mx-auto"><?= e(t('home.cta.text')) ?></p>
        <a class="btn btn-primary btn-lg" href="<?= e(url('/register')) ?>"><?= e(t('home.cta.button')) ?></a>
    </div>
</section>
