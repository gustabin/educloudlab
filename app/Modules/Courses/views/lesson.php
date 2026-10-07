<?php
/**
 * @var array<string, mixed>       $lesson rendered lesson (html is CommonMark output with raw HTML escaped)
 * @var list<array<string, mixed>> $courseLabs labs assigned to the course (editor select)
 * @var array<string, mixed>|null  $myAttempt my attempt on the linked lab
 * @var bool $isStudent
 * @var bool $canStart
 */
$manage = (bool) $lesson['can_manage'];
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="<?= e(url('/app/courses')) ?>"><?= e(t('courses.title')) ?></a></li>
        <li class="breadcrumb-item"><a href="<?= e(url('/app/courses/' . $lesson['course']['id'])) ?>"><?= e($lesson['course']['title']) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($lesson['title']) ?></li>
    </ol>
</nav>

<div id="course" data-course-id="<?= e($lesson['course']['id']) ?>">
<article id="lesson" data-lesson-id="<?= e($lesson['id']) ?>" aria-labelledby="lesson-title">
    <div class="ec-page-header mb-3">
        <div>
            <span class="small text-body-secondary"><?= e($lesson['module']['title']) ?></span>
            <h1 class="h3 mb-1" id="lesson-title"><?= e($lesson['title']) ?>
<?php if ($manage && $lesson['status'] === 'draft'): ?>
                <span class="ec-badge-draft"><?= e(t('content.draft')) ?></span>
<?php endif; ?>
            </h1>
            <p class="small text-body-secondary mb-0">
<?php if ($lesson['estimated_minutes'] !== null): ?>
                <?= e(t('labs.minutes', ['n' => $lesson['estimated_minutes']])) ?>
<?php endif; ?>
<?php if ($lesson['due_at'] !== null): ?>
                · <?= e(t('courses.due', ['date' => substr((string) $lesson['due_at'], 0, 10)])) ?>
<?php endif; ?>
            </p>
        </div>
<?php if ($isStudent): ?>
        <button class="btn <?= $lesson['completed'] ? 'btn-success' : 'btn-outline-primary' ?>" type="button" id="lesson-complete"
                data-completed="<?= $lesson['completed'] ? '1' : '0' ?>" aria-pressed="<?= $lesson['completed'] ? 'true' : 'false' ?>">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <?= e($lesson['completed'] ? t('content.completed') : t('content.mark_complete')) ?>
        </button>
<?php endif; ?>
    </div>

    <div class="ec-card ec-markdown mb-3"><?= $lesson['html'] /* CommonMark: raw HTML escaped, unsafe links dropped */ ?></div>

<?php if ($lesson['lab'] !== null): ?>
    <section class="ec-card mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2" aria-labelledby="lesson-lab-title">
        <div>
            <h2 class="h6 mb-0" id="lesson-lab-title"><i class="fa-solid fa-flask me-1" aria-hidden="true"></i><?= e(t('content.practice')) ?></h2>
            <span class="small"><span class="font-monospace"><?= e($lesson['lab']['code']) ?></span> · <?= e($lesson['lab']['title']) ?></span>
        </div>
<?php if ($isStudent && $myAttempt !== null && $myAttempt['open']): ?>
        <a class="btn btn-sm btn-primary" href="<?= e(url('/app/lab-attempts/' . $myAttempt['id'])) ?>"><?= e(t('labs.continue')) ?></a>
<?php elseif ($isStudent && $canStart): ?>
        <button class="btn btn-sm btn-primary" type="button" data-course-lab-start="<?= e($lesson['lab']['code']) ?>"><?= e(t('labs.start')) ?></button>
<?php endif; ?>
    </section>
<?php endif; ?>

    <nav class="d-flex justify-content-between gap-2 mb-3" aria-label="<?= e(t('content.lesson_nav')) ?>">
<?php if ($lesson['previous_id'] !== null): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/app/lessons/' . $lesson['previous_id'])) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= e(t('content.previous')) ?></a>
<?php else: ?>
        <span></span>
<?php endif; ?>
<?php if ($lesson['next_id'] !== null): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/app/lessons/' . $lesson['next_id'])) ?>"><?= e(t('content.next')) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
<?php endif; ?>
    </nav>

<?php if ($manage): ?>
    <form class="ec-card" id="lesson-edit-form" novalidate aria-labelledby="lesson-edit-title">
        <h2 class="h6" id="lesson-edit-title"><?= e(t('content.edit_lesson')) ?></h2>
        <div class="row g-2">
            <div class="col-12 col-md-6">
                <label class="form-label small" for="lesson-edit-name"><?= e(t('content.lesson_title')) ?></label>
                <input class="form-control form-control-sm" id="lesson-edit-name" name="title" maxlength="150" value="<?= e($lesson['title']) ?>" required>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small" for="lesson-edit-minutes"><?= e(t('content.minutes')) ?></label>
                <input class="form-control form-control-sm" type="number" min="1" max="600" id="lesson-edit-minutes" name="estimated_minutes"
                       value="<?= e((string) ($lesson['estimated_minutes'] ?? '')) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small" for="lesson-edit-due"><?= e(t('courses.due_label')) ?></label>
                <input class="form-control form-control-sm" type="date" id="lesson-edit-due" name="due_at" value="<?= e(substr((string) ($lesson['due_at'] ?? ''), 0, 10)) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small" for="lesson-edit-lab"><?= e(t('content.lab')) ?></label>
                <select class="form-select form-select-sm" id="lesson-edit-lab" name="lab_code">
                    <option value=""><?= e(t('content.no_lab')) ?></option>
<?php foreach ($courseLabs as $lab): ?>
                    <option value="<?= e($lab['code']) ?>"<?= ($lesson['lab']['code'] ?? null) === $lab['code'] ? ' selected' : '' ?>><?= e($lab['code']) ?></option>
<?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label small" for="lesson-edit-body"><?= e(t('content.body')) ?></label>
                <textarea class="form-control font-monospace" id="lesson-edit-body" name="body_md" rows="14" maxlength="50000"
                          aria-describedby="lesson-edit-help"><?= e((string) $lesson['body_md']) ?></textarea>
                <div class="form-text" id="lesson-edit-help"><?= e(t('content.body_help')) ?></div>
            </div>
        </div>
        <button class="btn btn-sm btn-primary mt-2" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> <?= e(t('content.save')) ?></button>
    </form>
<?php endif; ?>
</article>
</div>
