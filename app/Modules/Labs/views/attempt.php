<?php
/**
 * Lab interface. Instructions are trusted CommonMark output (raw HTML escaped, unsafe links removed) and are the
 * only values printed without e(). Everything that changes (results, status, hints) is updated by labs.js with .text().
 *
 * @var array<string, mixed>  $attempt
 * @var string                $introHtml
 * @var array<string, string> $instructionsHtml
 * @var int                   $maxSql
 */
$lab = $attempt['lab'];
$ws = $attempt['workspace'];
$closed = in_array($attempt['status'], ['abandoned', 'expired'], true) || $ws === null || $ws['status'] !== 'active';
$editable = $attempt['is_owner'] && !$closed;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/labs')) ?>"><?= e(t('labs.title')) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($lab['code']) ?></li>
    </ol>
</nav>

<div id="lab-attempt" data-attempt-id="<?= e($attempt['id']) ?>" data-editable="<?= $editable ? '1' : '0' ?>">
    <div class="ec-page-header mb-3">
        <div>
            <span class="small text-body-secondary font-monospace"><?= e($lab['code']) ?> · v<?= e($lab['version']) ?></span>
            <h1 class="h3 mb-1"><?= e($lab['title']) ?></h1>
            <p class="text-body-secondary mb-0 small">
                <span class="ec-difficulty ec-difficulty-<?= e($lab['difficulty']) ?>"><?= e(t('labs.difficulty.' . $lab['difficulty'])) ?></span>
                <span class="ms-2"><i class="fa-regular fa-clock" aria-hidden="true"></i> <?= e(t('labs.minutes', ['n' => $lab['estimated_minutes']])) ?></span>
<?php if (!$attempt['is_owner']): ?>
                <span class="ms-2"><i class="fa-regular fa-user" aria-hidden="true"></i> <?= e($attempt['student']['display_name']) ?></span>
<?php endif; ?>
<?php if ($attempt['course'] !== null): ?>
                <a class="ms-2" href="<?= e(url('/app/courses/' . $attempt['course']['id'])) ?>"><i class="fa-solid fa-chalkboard-user" aria-hidden="true"></i> <?= e(t('labs.course', ['title' => $attempt['course']['title']])) ?></a>
<?php endif; ?>
            </p>
        </div>
        <div class="text-end">
            <div class="ec-score" aria-live="polite">
                <span class="ec-score-value" id="lab-best"><?= e(fmt_number($attempt['best_score'])) ?></span>
                <span class="text-body-secondary">/ <?= e(fmt_number($attempt['max_score'])) ?></span>
            </div>
            <span class="small text-body-secondary"><?= e(t('labs.best_label')) ?></span>
        </div>
    </div>

<?php if ($closed): ?>
    <div class="alert alert-secondary" role="status"><?= e(t('labs.closed')) ?> <a href="<?= e(url('/app/labs')) ?>"><?= e(t('labs.back_catalog')) ?></a></div>
<?php endif; ?>
    <div class="alert alert-info d-none" id="lab-preparing" role="status">
        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?= e(t('labs.preparing')) ?>
    </div>
    <div class="alert alert-danger d-none" id="lab-error" role="alert"></div>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <section class="ec-card mb-3 ec-prose" aria-labelledby="lab-intro-title">
                <h2 class="h5" id="lab-intro-title"><?= e(t('labs.intro')) ?></h2>
                <?= $introHtml ?>
                <h3 class="h6 mt-3"><?= e(t('labs.objectives')) ?></h3>
                <ul>
<?php foreach ($lab['objectives'] as $objective): ?>
                    <li><?= e($objective) ?></li>
<?php endforeach; ?>
                </ul>
<?php if ($lab['downloads'] !== []): ?>
                <h3 class="h6 mt-3"><?= e(t('labs.downloads')) ?></h3>
                <ul class="list-unstyled mb-0">
<?php foreach ($lab['downloads'] as $download): ?>
                    <li><a href="<?= e($download['url']) ?>" download="<?= e($download['name']) ?>"><i class="fa-solid fa-download" aria-hidden="true"></i> <?= e($download['name']) ?></a></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </section>

<?php foreach ($attempt['tasks'] as $n => $task):
    $key = $task['key'];
    ?>
            <section class="ec-card mb-3 ec-task" id="task-<?= e($key) ?>" data-task="<?= e($key) ?>" aria-labelledby="task-<?= e($key) ?>-title">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <h2 class="h5 mb-0" id="task-<?= e($key) ?>-title"><?= e(($n + 1) . '. ' . $task['title']) ?></h2>
                    <span class="small text-nowrap text-body-secondary"><?= e(t('labs.points', ['n' => $task['points']])) ?></span>
                </div>
                <div class="ec-task-result mb-2" data-result aria-live="polite"></div>
                <div class="ec-prose"><?= $instructionsHtml[$key] ?? '' ?></div>

<?php if ($task['answer']): ?>
                <form class="mt-3" data-answer-form novalidate>
                    <label class="form-label small fw-semibold" for="answer-<?= e($key) ?>"><?= e(t('labs.answer_label')) ?></label>
                    <textarea class="form-control font-monospace ec-answer" id="answer-<?= e($key) ?>" name="sql" rows="5" spellcheck="false"
                              maxlength="<?= e($maxSql) ?>"<?= $editable ? '' : ' readonly' ?>><?= e($task['answer_sql'] ?? '') ?></textarea>
<?php if ($editable): ?>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <button class="btn btn-sm btn-outline-primary" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> <?= e(t('labs.answer_save')) ?></button>
                        <span class="small text-body-secondary" data-answer-status aria-live="polite"><?= e($task['answer_sql'] === null ? t('labs.answer_none') : t('labs.answer_saved')) ?></span>
                    </div>
<?php endif; ?>
                </form>
<?php endif; ?>

<?php if ($task['hints'] !== []): ?>
                <div class="mt-3 ec-hints" data-hints>
<?php foreach ($task['hints'] as $hint): ?>
<?php if ($hint['text'] !== null): ?>
                    <div class="ec-hint" data-hint-index="<?= e($hint['index']) ?>"><i class="fa-regular fa-lightbulb" aria-hidden="true"></i> <span><?= e($hint['text']) ?></span></div>
<?php elseif ($editable): ?>
                    <button class="btn btn-sm btn-link px-0" type="button" data-hint-index="<?= e($hint['index']) ?>" data-penalty="<?= e($hint['penalty']) ?>">
                        <i class="fa-regular fa-lightbulb" aria-hidden="true"></i> <?= e(t('labs.hint_show', ['n' => $hint['index'] + 1, 'penalty' => $hint['penalty']])) ?>
                    </button>
<?php endif; ?>
<?php endforeach; ?>
                </div>
<?php endif; ?>
            </section>
<?php endforeach; ?>
        </div>

        <aside class="col-12 col-lg-4">
            <div class="ec-card ec-lab-panel">
                <h2 class="h6"><?= e(t('labs.environment')) ?></h2>
<?php if ($ws !== null && $ws['status'] === 'active'): ?>
                <div class="d-grid gap-2 mb-3">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/app/workspaces/' . $ws['id'])) ?>"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> <?= e(t('labs.open_workspace')) ?></a>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/app/workspaces/' . $ws['id'] . '/sql')) ?>"><i class="fa-solid fa-terminal" aria-hidden="true"></i> <?= e(t('labs.open_sql')) ?></a>
                </div>
                <p class="small text-body-secondary" id="lab-expiry" data-expires="<?= e($ws['expires_at']) ?>"></p>
<?php else: ?>
                <p class="small text-body-secondary"><?= e(t('labs.environment_gone')) ?></p>
<?php endif; ?>
                <dl class="small row mb-3">
                    <dt class="col-7 fw-normal text-body-secondary"><?= e(t('labs.status')) ?></dt>
                    <dd class="col-5 mb-1 text-end"><span class="ec-attempt ec-attempt-<?= e($attempt['status']) ?>" id="lab-status"><?= e(t('labs.state.' . $attempt['status'])) ?></span></dd>
                    <dt class="col-7 fw-normal text-body-secondary"><?= e(t('labs.last_score')) ?></dt>
                    <dd class="col-5 mb-1 text-end" id="lab-score"><?= e($attempt['submissions'] > 0 ? fmt_number($attempt['score']) : '—') ?></dd>
                    <dt class="col-7 fw-normal text-body-secondary"><?= e(t('labs.submissions')) ?></dt>
                    <dd class="col-5 mb-1 text-end" id="lab-submissions"><?= e($attempt['submissions']) ?></dd>
                    <dt class="col-7 fw-normal text-body-secondary"><?= e(t('labs.hints_used')) ?></dt>
                    <dd class="col-5 mb-0 text-end" id="lab-hints-used"><?= e($attempt['hints_used']) ?></dd>
                </dl>
<?php if ($editable): ?>
                <div class="d-grid gap-2">
                    <button class="btn btn-primary" type="button" id="lab-submit"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= e(t('labs.submit')) ?></button>
                    <p class="small text-body-secondary mb-1"><?= e(t('labs.submit_help')) ?></p>
                    <button class="btn btn-outline-danger btn-sm" type="button" id="lab-abandon"><?= e(t('labs.abandon')) ?></button>
                </div>
<?php endif; ?>
            </div>
        </aside>
    </div>
</div>
