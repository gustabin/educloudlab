<?php

declare(strict_types=1);

namespace EduCloud\Core;

use EduCloud\Core\Auth\AuthUser;
use EduCloud\Core\Auth\JwtService;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Modules\Audit\AuditLogger;
use EduCloud\Modules\Tenants\TenantRepository;

/**
 * Application container: shared services, created once per request (or per test).
 * Deliberately small: no service locator magic, just typed accessors.
 */
final class App
{
    private ?Db $db = null;
    private ?View $view = null;
    private ?JwtService $jwt = null;
    private ?Csrf $csrf = null;

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

    public function jwt(): JwtService
    {
        return $this->jwt ??= new JwtService($this->config);
    }

    public function csrf(): Csrf
    {
        return $this->csrf ??= new Csrf((string) $this->config->get('app.hash_key'));
    }

    public function rateLimiter(): RateLimiter
    {
        return new RateLimiter($this->db(), $this->config);
    }

    public function audit(): AuditLogger
    {
        return new AuditLogger($this->db(), $this->logger, (string) $this->config->get('app.hash_key'));
    }

    /** CSRF token for the current browser context (session-bound when logged in, else anonymous-cookie-bound). */
    public function csrfToken(Request $request): string
    {
        $session = $request->attribute('session');
        if (is_array($session)) {
            return $this->csrf()->forSession((string) $session['raw_id']);
        }
        return $this->csrf()->forAnonymous((string) $request->attribute('csrf_anon', ''));
    }

    /**
     * Renders an HTML page with the shared layout data (CSRF token, current user, active tenant).
     *
     * @param array<string, mixed> $data
     */
    public function renderPage(Request $request, string $template, array $data = [], int $status = 200, string $layout = 'layouts/main'): Response
    {
        $user = $request->attribute('user');
        $tenant = $request->attribute('tenant');
        $data += [
            'csrfToken' => $this->csrfToken($request),
            'currentUser' => $user instanceof AuthUser ? $user : null,
            'tenantContext' => $tenant instanceof TenantContext ? $tenant : null,
            'tenantName' => $request->attribute('tenant_name'),
        ];
        if ($layout === 'layouts/app' && $user instanceof AuthUser) {
            $data += ['memberships' => (new TenantRepository($this->db()))->listActiveMemberships($user->id)];
        }
        return Response::html($this->view()->render($template, $data, $layout), $status);
    }

    public function isDebug(): bool
    {
        return (bool) $this->config->get('app.debug') && $this->config->get('app.env') !== 'production';
    }
}
