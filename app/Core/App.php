<?php

declare(strict_types=1);

namespace EduCloud\Core;

/**
 * Application container: shared services, created once per request (or per test).
 * Deliberately small: no service locator magic, just typed accessors.
 */
final class App
{
    private ?Db $db = null;
    private ?View $view = null;

    public function __construct(
        public readonly Config $config,
        public readonly Logger $logger,
        public readonly Router $router,
        private readonly bool $testDatabase = false,
    ) {
    }

    /** Builds the app and registers every module's routes. */
    public static function create(Config $config, bool $testDatabase = false): self
    {
        $storage = (string) $config->get('app.storage_path');
        $app = new self($config, new Logger($storage . '/logs'), new Router(), $testDatabase);

        $modules = glob($config->get('root_path') . '/app/Modules/*/routes.php') ?: [];
        sort($modules);
        foreach ($modules as $routesFile) {
            $register = require $routesFile;
            $register($app->router, $app);
        }
        return $app;
    }

    public function db(): Db
    {
        return $this->db ??= Db::fromConfig($this->config, $this->testDatabase);
    }

    public function view(): View
    {
        return $this->view ??= new View((string) $this->config->get('root_path') . '/app', $this->config);
    }

    public function isDebug(): bool
    {
        return (bool) $this->config->get('app.debug') && $this->config->get('app.env') !== 'production';
    }
}
