<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Config;
use EduCloud\Core\I18n;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Promote warnings/notices to exceptions so they are handled (and logged) consistently.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$config = Config::load($root);
I18n::setLocale((string) $config->get('app.locale', 'es'));

return App::create($config);
