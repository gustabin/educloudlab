<?php

declare(strict_types=1);

use EduCloud\Core\App;
use EduCloud\Core\Request;
use EduCloud\Core\Router;
use EduCloud\Modules\Auth\AuthController;
use EduCloud\Modules\Auth\AuthPageController;

return static function (Router $router, App $app): void {
    $api = static fn (string $method) => static fn (Request $r, App $app) => (new AuthController($app))->$method($r);
    $page = static fn (string $method) => static fn (Request $r, App $app) => (new AuthPageController($app))->$method($r);

    $authIp = ['auth_ip'];

    // --- REST API ---------------------------------------------------------------------------------
    $router->post('/api/v1/auth/register', $api('register'), ['rate' => ['auth_ip', 'register_ip'], 'name' => 'auth.register']);
    $router->post('/api/v1/auth/verify-email', $api('verifyEmail'), ['rate' => $authIp, 'name' => 'auth.verify_email']);
    $router->post('/api/v1/auth/verify-email/resend', $api('resendVerification'), ['rate' => $authIp, 'name' => 'auth.verify_email_resend']);
    $router->post('/api/v1/auth/login', $api('login'), ['rate' => $authIp, 'name' => 'auth.login']);
    $router->post('/api/v1/auth/logout', $api('logout'), ['auth' => 'session', 'name' => 'auth.logout']);
    $router->get('/api/v1/auth/me', $api('me'), ['auth' => 'any', 'name' => 'auth.me']);
    $router->post('/api/v1/auth/password/forgot', $api('forgotPassword'), ['rate' => $authIp, 'name' => 'auth.password_forgot']);
    $router->post('/api/v1/auth/password/reset', $api('resetPassword'), ['rate' => $authIp, 'name' => 'auth.password_reset']);

    // Credential exchange for non-browser API clients: no cookies are read, so CSRF does not apply.
    $router->post('/api/v1/auth/tokens', $api('issueTokens'), ['rate' => $authIp, 'csrf' => false, 'name' => 'auth.tokens']);
    $tokenOpts = ['rate' => ['token_refresh_ip'], 'csrf' => false];
    $router->post('/api/v1/auth/tokens/refresh', $api('refreshTokens'), $tokenOpts + ['name' => 'auth.tokens_refresh']);
    $router->post('/api/v1/auth/tokens/revoke', $api('revokeTokens'), $tokenOpts + ['name' => 'auth.tokens_revoke']);

    // --- Pages ------------------------------------------------------------------------------------
    $router->get('/login', $page('login'), ['name' => 'page.login']);
    $router->get('/register', $page('register'), ['name' => 'page.register']);
    $router->get('/verify-email', $page('verifyEmail'), ['name' => 'page.verify_email']);
    $router->get('/forgot-password', $page('forgotPassword'), ['name' => 'page.forgot_password']);
    $router->get('/reset-password', $page('resetPassword'), ['name' => 'page.reset_password']);
};
