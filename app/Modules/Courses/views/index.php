<?php
/**
 * @var list<array<string, mixed>> $courses
 * @var bool $canCreate
 * @var bool $isOrganization
 */
?>
<div class="ec-page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(t('courses.title')) ?></h1>
        <p class="text-body-secondary mb-0"><?= e(t('courses.lead')) ?></p>
    </div>
<?php if ($canCreate): ?>
    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#course-create-modal">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('courses.create')) ?>
    </button>
<?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
<?php if ($courses === []): ?>
        <div class="ec-empty">
            <span class="ec-feature-icon"><i class="fa-solid fa-chalkboard-user" aria-hidden="true"></i></span>
            <h2 class="h5"><?= e(t('courses.empty_title')) ?></h2>
            <p class="text-body-secondary mb-0"><?= e($isOrganization ? t('courses.empty_text') : t('courses.empty_personal')) ?></p>
        </div>
<?php else: ?>
        <div class="list-group" id="course-list">
<?php foreach ($courses as $course): ?>
            <a class="list-group-item list-group-item-action py-3" href="<?= e(url('/app/courses/' . $course['id'])) ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <span class="small text-body-secondary font-monospace"><?= e($course['code']) ?></span>
                        <div class="fw-semibold"><?= e($course['title']) ?></div>
                        <div class="small text-body-secondary">
                            <?= e(t('courses.labs_count', ['n' => $course['lab_count']])) ?>
<?php if ($course['student_count'] !== null): ?>
                            · <?= e(t('courses.students_count', ['n' => $course['student_count']])) ?>
<?php endif; ?>
                            · <?= e($course['my_role'] === 'staff' ? t('courses.role.staff') : t('courses.role.student')) ?>
                        </div>
                    </div>
                    <span class="ec-course-status ec-course-status-<?= e($course['status']) ?>"><?= e(t('courses.status.' . $course['status'])) ?></span>
                </div>
            </a>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>

    <aside class="col-12 col-lg-4">
        <form class="ec-card" id="course-join-form" novalidate>
            <h2 class="h6"><label for="course-join-code"><?= e(t('courses.join_title')) ?></label></h2>
            <p class="small text-body-secondary"><?= e(t('courses.join_help')) ?></p>
            <input class="form-control font-monospace text-uppercase mb-2" id="course-join-code" name="code" maxlength="20"
                   autocomplete="off" placeholder="ABCDE-23456" required>
            <button class="btn btn-outline-primary w-100" type="submit"><?= e(t('courses.join')) ?></button>
        </form>
    </aside>
</div>

<?php if ($canCreate): ?>
<div class="modal fade" id="course-create-modal" tabindex="-1" aria-labelledby="course-create-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="course-create-form" novalidate>
            <div class="modal-header">
                <h2 class="modal-title h5" id="course-create-title"><?= e(t('courses.create')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('nav.close')) ?>"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="course-code"><?= e(t('courses.code')) ?></label>
                    <input class="form-control font-monospace text-uppercase" id="course-code" name="code" required maxlength="30" placeholder="DATA-101"
                           aria-describedby="course-code-help" autocomplete="off">
                    <div class="form-text" id="course-code-help"><?= e(t('courses.code_help')) ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="course-title"><?= e(t('courses.course_title')) ?></label>
                    <input class="form-control" id="course-title" name="title" required maxlength="150">
                </div>
                <div>
                    <label class="form-label" for="course-description"><?= e(t('courses.description')) ?></label>
                    <textarea class="form-control" id="course-description" name="description" rows="3" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(t('courses.create')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
