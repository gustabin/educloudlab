<?php
/**
 * @var array<string, mixed>                     $course
 * @var array<string, array<string, mixed>|null> $myAttempts lab code => my attempt summary
 * @var list<array<string, mixed>>               $availableLabs
 * @var bool $canReview
 * @var bool $canStart
 */
$staff = $course['my_role'] === 'staff';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/courses')) ?>"><?= e(t('courses.title')) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($course['code']) ?></li>
    </ol>
</nav>

<div id="course" data-course-id="<?= e($course['id']) ?>">
    <div class="ec-page-header mb-3">
        <div>
            <span class="small text-body-secondary font-monospace"><?= e($course['code']) ?></span>
            <h1 class="h3 mb-1"><?= e($course['title']) ?></h1>
            <p class="small text-body-secondary mb-0">
                <span class="ec-course-status ec-course-status-<?= e($course['status']) ?>"><?= e(t('courses.status.' . $course['status'])) ?></span>
                <span class="ms-2"><?= e(t('courses.teacher', ['name' => $course['owner']['display_name']])) ?></span>
            </p>
        </div>
<?php if ($canReview): ?>
        <a class="btn btn-primary" href="<?= e(url('/app/courses/' . $course['id'] . '/progress')) ?>">
            <i class="fa-solid fa-table-cells" aria-hidden="true"></i> <?= e(t('courses.progress')) ?>
        </a>
<?php endif; ?>
    </div>

<?php if ($course['description'] !== null): ?>
    <p class="ec-course-description"><?= nl2br(e($course['description'])) ?></p>
<?php endif; ?>

    <div class="row g-3">
        <div class="col-12<?= $course['can_manage'] ? ' col-lg-8' : '' ?>">
            <section class="ec-card" aria-labelledby="course-labs-title">
                <h2 class="h5" id="course-labs-title"><?= e(t('courses.labs')) ?></h2>
<?php if ($course['labs'] === []): ?>
                <p class="text-body-secondary mb-0"><?= e($staff ? t('courses.labs_empty_staff') : t('courses.labs_empty')) ?></p>
<?php else: ?>
                <ul class="list-group list-group-flush">
<?php foreach ($course['labs'] as $lab):
    $mine = $myAttempts[$lab['code']] ?? null;
    ?>
                    <li class="list-group-item px-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="small text-body-secondary font-monospace"><?= e($lab['code']) ?></span>
                            <div class="fw-semibold"><?= e($lab['title']) ?></div>
                            <div class="small text-body-secondary">
                                <?= e(t('labs.difficulty.' . $lab['difficulty'])) ?> · <?= e(t('labs.minutes', ['n' => $lab['estimated_minutes']])) ?>
                                · <?= e($lab['required'] ? t('courses.required') : t('courses.optional')) ?>
<?php if ($lab['due_at'] !== null): ?>
                                · <?= e(t('courses.due', ['date' => substr((string) $lab['due_at'], 0, 10)])) ?>
<?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
<?php if (!$staff): ?>
<?php if ($mine !== null): ?>
                            <span class="ec-attempt ec-attempt-<?= e($mine['status']) ?>"><?= e(t('labs.state.' . $mine['status'])) ?></span>
                            <span class="small text-body-secondary"><?= e(t('labs.best', ['score' => fmt_number($mine['best_score']), 'max' => fmt_number($lab['max_score'])])) ?></span>
<?php endif; ?>
<?php if ($mine !== null && $mine['open']): ?>
                            <a class="btn btn-sm btn-primary" href="<?= e(url('/app/lab-attempts/' . $mine['id'])) ?>"><?= e(t('labs.continue')) ?></a>
<?php elseif ($canStart && $course['status'] === 'published'): ?>
                            <button class="btn btn-sm btn-primary" type="button" data-course-lab-start="<?= e($lab['code']) ?>"><?= e(t('labs.start')) ?></button>
<?php endif; ?>
<?php elseif ($course['can_manage']): ?>
                            <button class="btn btn-sm btn-outline-danger" type="button" data-course-lab-remove="<?= e($lab['code']) ?>"
                                    aria-label="<?= e(t('courses.unassign_named', ['lab' => $lab['code']])) ?>">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
<?php endif; ?>
                        </div>
                    </li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </section>
        </div>

<?php if ($course['can_manage']): ?>
        <aside class="col-12 col-lg-4">
            <section class="ec-card mb-3" aria-labelledby="course-access-title">
                <h2 class="h6" id="course-access-title"><?= e(t('courses.access')) ?></h2>
                <p class="small text-body-secondary" id="course-join-state">
                    <?= e($course['status'] !== 'published' ? t('courses.access_unpublished') : ($course['join_enabled'] ? t('courses.access_on') : t('courses.access_off'))) ?>
                </p>
                <div class="d-grid gap-2">
<?php if ($course['status'] === 'draft'): ?>
                    <button class="btn btn-sm btn-primary" type="button" data-course-status="published"><?= e(t('courses.publish')) ?></button>
<?php elseif ($course['status'] === 'published'): ?>
                    <button class="btn btn-sm btn-outline-primary" type="button" id="course-join-rotate"><?= e(t('courses.code_generate')) ?></button>
<?php if ($course['join_enabled']): ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="course-join-disable"><?= e(t('courses.code_disable')) ?></button>
<?php endif; ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-course-status="archived"><?= e(t('courses.archive')) ?></button>
<?php else: ?>
                    <button class="btn btn-sm btn-outline-primary" type="button" data-course-status="published"><?= e(t('courses.reopen')) ?></button>
<?php endif; ?>
                </div>
                <p class="small text-body-secondary mt-2 mb-0">
                    <?= e(t('courses.students_count', ['n' => $course['student_count']])) ?>
                </p>
                <hr>
                <p class="small mb-2"><?= e($course['visibility'] === 'public' ? t('courses.visibility_public') : t('courses.visibility_private')) ?>
<?php if ($course['public_url'] !== null): ?>
                    <a href="<?= e($course['public_url']) ?>"><?= e(t('courses.public_page')) ?></a>
<?php endif; ?>
                </p>
                <button class="btn btn-sm btn-outline-secondary w-100" type="button" data-course-visibility="<?= e($course['visibility'] === 'public' ? 'private' : 'public') ?>">
                    <?= e($course['visibility'] === 'public' ? t('courses.make_private') : t('courses.make_public')) ?>
                </button>
            </section>

<?php if ($availableLabs !== []): ?>
            <form class="ec-card" id="course-assign-form" novalidate>
                <h2 class="h6"><?= e(t('courses.assign')) ?></h2>
                <div class="mb-2">
                    <label class="form-label small" for="course-assign-lab"><?= e(t('courses.assign_lab')) ?></label>
                    <select class="form-select form-select-sm" id="course-assign-lab" name="lab_code">
<?php foreach ($availableLabs as $lab): ?>
                        <option value="<?= e($lab['code']) ?>"><?= e($lab['code'] . ' · ' . $lab['title']) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="course-assign-due"><?= e(t('courses.due_label')) ?></label>
                    <input class="form-control form-control-sm" type="date" id="course-assign-due" name="due_at">
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="course-assign-required" name="required" checked>
                    <label class="form-check-label small" for="course-assign-required"><?= e(t('courses.required')) ?></label>
                </div>
                <button class="btn btn-sm btn-primary w-100" type="submit"><?= e(t('courses.assign')) ?></button>
            </form>
<?php endif; ?>
        </aside>
<?php endif; ?>
    </div>
</div>
