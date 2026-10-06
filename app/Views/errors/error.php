<?php
/**
 * @var int    $status
 * @var string $title
 * @var string $message
 * @var string $requestId
 */
?>
<section class="container ec-error">
    <p class="ec-error-code" aria-hidden="true"><?= e($status) ?></p>
    <h1 class="h3 mb-3"><?= e($title) ?></h1>
    <p class="text-body-secondary mb-4"><?= e($message) ?></p>
    <p class="ec-request-id"><?= e(t('error.request_id')) ?>: <?= e($requestId) ?></p>
    <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= e(t('error.back_home')) ?></a>
</section>
