<?php

declare(strict_types=1);

namespace EduCloud\Core;

use RuntimeException;

/**
 * Plain-PHP templates. Every dynamic value in a template must be printed with e() (see helpers.php).
 *
 * Template names:
 *   'errors/error'          -> app/Views/errors/error.php
 *   'PublicSite::home'      -> app/Modules/PublicSite/views/home.php
 * Layouts live in app/Views/layouts and receive $content plus the page data.
 */
final class View
{
    public function __construct(private readonly string $appPath, private readonly Config $config)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $data += [
            'appName' => (string) $this->config->get('app.name'),
            'locale' => (string) $this->config->get('app.locale', 'es'),
            'pageTitle' => null,
            'metaDescription' => null,
            'canonical' => null,
            'indexable' => false,
            'csrfToken' => '',
            'currentUser' => null,
            'tenantContext' => null,
            'tenantName' => null,
        ];
        $content = $this->renderFile($this->resolve($template), $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile($this->resolve($layout), $data + ['content' => $content]);
    }

    private function resolve(string $template): string
    {
        if (preg_match('/^[A-Za-z0-9_\/:-]+$/D', $template) !== 1 || str_contains($template, '..')) {
            throw new RuntimeException('Invalid template name');
        }
        if (str_contains($template, '::')) {
            [$module, $name] = explode('::', $template, 2);
            $file = $this->appPath . '/Modules/' . $module . '/views/' . $name . '.php';
        } else {
            $file = $this->appPath . '/Views/' . $template . '.php';
        }
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $template);
        }
        return $file;
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $__file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
