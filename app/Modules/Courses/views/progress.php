<?php
/** @var array<string, mixed> $progress */
$course = $progress['course'];
$labs = $progress['labs'];
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/courses')) ?>"><?= e(t('courses.title')) ?></a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('/app/courses/' . $course['id'])) ?>"><?= e($course['code']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e(t('courses.progress')) ?></li>
    </ol>
</nav>

<div class="ec-page-header mb-3">
    <div>
        <h1 class="h3 mb-1"><?= e(t('courses.progress')) ?></h1>
        <p class="text-body-secondary mb-0 small"><?= e($course['title']) ?> · <?= e(t('courses.progress_lead')) ?></p>
    </div>
</div>

<?php if ($labs === [] || $progress['students'] === []): ?>
<div class="ec-empty">
    <span class="ec-feature-icon"><i class="fa-solid fa-table-cells" aria-hidden="true"></i></span>
    <p class="text-body-secondary mb-0"><?= e($labs === [] ? t('courses.progress_no_labs') : t('courses.progress_no_students')) ?></p>
</div>
<?php else: ?>
<div class="ec-card p-0">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 ec-progress-table">
            <caption class="visually-hidden"><?= e(t('courses.progress_caption')) ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?= e(t('courses.student')) ?></th>
<?php foreach ($labs as $lab): ?>
                    <th scope="col" class="text-center"><abbr title="<?= e($lab['title']) ?>"><?= e($lab['code']) ?></abbr></th>
<?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
<?php foreach ($progress['students'] as $student): ?>
                <tr>
                    <th scope="row" class="fw-normal"><?= e($student['display_name']) ?></th>
<?php foreach ($labs as $lab):
    $cell = $student['labs'][$lab['code']];
    ?>
                    <td class="text-center">
<?php if ($cell === null): ?>
                        <span class="text-body-secondary" title="<?= e(t('labs.not_started')) ?>">—<span class="visually-hidden"><?= e(t('labs.not_started')) ?></span></span>
<?php else: ?>
                        <a class="ec-progress-cell ec-attempt ec-attempt-<?= e($cell['status']) ?>" href="<?= e(url('/app/lab-attempts/' . $cell['attempt_id'])) ?>"
                           title="<?= e(t('labs.state.' . $cell['status'])) ?>">
                            <?= e(fmt_number($cell['best_score'])) ?>/<?= e(fmt_number($cell['max_score'])) ?>
                            <span class="visually-hidden"> · <?= e(t('labs.state.' . $cell['status'])) ?></span>
                        </a>
<?php if ($cell['failed_tasks'] !== null && $cell['failed_tasks'] > 0): ?>
                        <div class="small text-body-secondary"><?= e(t('courses.failed_tasks', ['n' => $cell['failed_tasks']])) ?></div>
<?php endif; ?>
<?php endif; ?>
                    </td>
<?php endforeach; ?>
                </tr>
<?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" class="small fw-semibold"><?= e(t('courses.summary')) ?></th>
<?php foreach ($labs as $lab):
    $s = $progress['summary'][$lab['code']];
    ?>
                    <td class="text-center small">
                        <?= e(t('courses.summary_cell', ['completed' => $s['completed'], 'started' => $s['started']])) ?>
<?php if ($s['average_best_score'] !== null): ?>
                        <div class="text-body-secondary"><?= e(t('courses.average', ['n' => fmt_number($s['average_best_score'])])) ?></div>
<?php endif; ?>
                    </td>
<?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>
