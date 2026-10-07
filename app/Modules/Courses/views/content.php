<?php
/**
 * Course content tree (M10b), included by show.php.
 *
 * @var array<string, mixed>       $course
 * @var list<array<string, mixed>> $modules
 */
$manage = (bool) $course['can_manage'];
$moduleCount = count($modules);
?>
<section class="ec-card mb-3" aria-labelledby="course-content-title" id="course-content">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h2 class="h5 mb-0" id="course-content-title"><?= e(t('content.title')) ?></h2>
<?php if ($manage): ?>
        <button class="btn btn-sm btn-outline-primary" type="button" data-content-new-module>
            <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(t('content.new_module')) ?>
        </button>
<?php endif; ?>
    </div>
<?php if ($modules === []): ?>
    <p class="text-body-secondary mb-0"><?= e($manage ? t('content.empty_staff') : t('content.empty')) ?></p>
<?php endif; ?>
<?php foreach ($modules as $mi => $module): ?>
    <div class="ec-module" data-module="<?= e($module['id']) ?>">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h3 class="h6 mb-0"><?= e($module['title']) ?>
<?php if ($manage && $module['status'] === 'draft'): ?>
                    <span class="ec-badge-draft"><?= e(t('content.draft')) ?></span>
<?php endif; ?>
                </h3>
<?php if ($module['summary'] !== null): ?>
                <p class="small text-body-secondary mb-1"><?= e($module['summary']) ?></p>
<?php endif; ?>
            </div>
<?php if ($manage): ?>
            <div class="btn-group btn-group-sm" role="group" aria-label="<?= e(t('content.module_actions', ['title' => $module['title']])) ?>">
                <button class="btn btn-outline-secondary" type="button" data-module-move="<?= $mi ?>" <?= $mi === 0 ? 'disabled' : '' ?>
                        aria-label="<?= e(t('content.move_up', ['title' => $module['title']])) ?>"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button>
                <button class="btn btn-outline-secondary" type="button" data-module-move="<?= $mi + 2 ?>" <?= $mi === $moduleCount - 1 ? 'disabled' : '' ?>
                        aria-label="<?= e(t('content.move_down', ['title' => $module['title']])) ?>"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button>
                <button class="btn btn-outline-secondary" type="button" data-module-status="<?= $module['status'] === 'draft' ? 'published' : 'draft' ?>">
                    <?= e($module['status'] === 'draft' ? t('content.publish') : t('content.unpublish')) ?>
                </button>
                <button class="btn btn-outline-secondary" type="button" data-module-edit data-title="<?= e($module['title']) ?>" data-summary="<?= e((string) $module['summary']) ?>"
                        aria-label="<?= e(t('content.edit_named', ['title' => $module['title']])) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                <button class="btn btn-outline-primary" type="button" data-lesson-new aria-label="<?= e(t('content.new_lesson_in', ['title' => $module['title']])) ?>">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                <button class="btn btn-outline-danger" type="button" data-module-delete data-title="<?= e($module['title']) ?>"
                        aria-label="<?= e(t('content.delete_named', ['title' => $module['title']])) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
            </div>
<?php endif; ?>
        </div>
<?php if ($module['lessons'] === []): ?>
        <p class="small text-body-secondary mb-0 mt-1"><?= e(t('content.no_lessons')) ?></p>
<?php else: ?>
        <ol class="ec-lessons">
<?php foreach ($module['lessons'] as $li => $lesson): ?>
            <li class="d-flex flex-wrap justify-content-between align-items-center gap-2" data-lesson="<?= e($lesson['id']) ?>">
                <div>
<?php if (!$manage): ?>
                    <i class="fa-solid <?= $lesson['completed'] ? 'fa-circle-check text-success' : 'fa-circle text-body-tertiary' ?> me-1" aria-hidden="true"></i>
                    <span class="visually-hidden"><?= e($lesson['completed'] ? t('content.completed') : t('content.pending')) ?></span>
<?php endif; ?>
                    <a href="<?= e(url('/app/lessons/' . $lesson['id'])) ?>"><?= e($lesson['title']) ?></a>
<?php if ($manage && $lesson['status'] === 'draft'): ?>
                    <span class="ec-badge-draft"><?= e(t('content.draft')) ?></span>
<?php endif; ?>
                    <span class="small text-body-secondary">
<?php if ($lesson['estimated_minutes'] !== null): ?>
                        · <?= e(t('labs.minutes', ['n' => $lesson['estimated_minutes']])) ?>
<?php endif; ?>
<?php if ($lesson['due_at'] !== null): ?>
                        · <?= e(t('courses.due', ['date' => substr((string) $lesson['due_at'], 0, 10)])) ?>
<?php endif; ?>
<?php if ($lesson['lab_code'] !== null): ?>
                        · <i class="fa-solid fa-flask" aria-hidden="true"></i> <?= e($lesson['lab_code']) ?>
<?php endif; ?>
                    </span>
                </div>
<?php if ($manage): ?>
                <div class="btn-group btn-group-sm" role="group" aria-label="<?= e(t('content.lesson_actions', ['title' => $lesson['title']])) ?>">
                    <button class="btn btn-outline-secondary" type="button" data-lesson-move="<?= $li ?>" <?= $li === 0 ? 'disabled' : '' ?>
                            aria-label="<?= e(t('content.move_up', ['title' => $lesson['title']])) ?>"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button>
                    <button class="btn btn-outline-secondary" type="button" data-lesson-move="<?= $li + 2 ?>" <?= $li === count($module['lessons']) - 1 ? 'disabled' : '' ?>
                            aria-label="<?= e(t('content.move_down', ['title' => $lesson['title']])) ?>"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button>
                    <button class="btn btn-outline-secondary" type="button" data-lesson-status="<?= $lesson['status'] === 'draft' ? 'published' : 'draft' ?>">
                        <?= e($lesson['status'] === 'draft' ? t('content.publish') : t('content.unpublish')) ?>
                    </button>
                    <button class="btn btn-outline-danger" type="button" data-lesson-delete data-title="<?= e($lesson['title']) ?>"
                            aria-label="<?= e(t('content.delete_named', ['title' => $lesson['title']])) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                </div>
<?php endif; ?>
            </li>
<?php endforeach; ?>
        </ol>
<?php endif; ?>
    </div>
<?php endforeach; ?>
</section>
