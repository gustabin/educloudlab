<?php

declare(strict_types=1);

namespace EduCloud\Modules\Auth;

use EduCloud\Core\App;
use EduCloud\Core\Auth\AuthUser;
use EduCloud\Core\Request;
use EduCloud\Core\Response;

/** Server-rendered auth pages. Forms submit via AJAX (public/assets/js/features/auth.js) to /api/v1/auth. */
final class AuthPageController
{
    public function __construct(private readonly App $app)
    {
    }

    public function login(Request $request): Response
    {
        if ($request->attribute('user') instanceof AuthUser) {
            return Response::redirect(url('/app'));
        }
        return $this->app->renderPage($request, 'Auth::login', [
            'pageTitle' => t('auth.login.title') . ' · EduCloud Lab',
            'next' => self::safeNext((string) ($request->query['next'] ?? '')),
            'notice' => is_string($request->query['notice'] ?? null) ? $request->query['notice'] : null,
        ]);
    }

    public function register(Request $request): Response
    {
        if ($request->attribute('user') instanceof AuthUser) {
            return Response::redirect(url('/app'));
        }
        return $this->app->renderPage($request, 'Auth::register', ['pageTitle' => t('auth.register.title') . ' · EduCloud Lab']);
    }

    public function verifyEmail(Request $request): Response
    {
        return $this->app->renderPage($request, 'Auth::verify_email', [
            'pageTitle' => t('auth.verify.title') . ' · EduCloud Lab',
            'token' => self::tokenFromQuery($request),
        ]);
    }

    public function forgotPassword(Request $request): Response
    {
        return $this->app->renderPage($request, 'Auth::forgot_password', ['pageTitle' => t('auth.forgot.title') . ' · EduCloud Lab']);
    }

    public function resetPassword(Request $request): Response
    {
        return $this->app->renderPage($request, 'Auth::reset_password', [
            'pageTitle' => t('auth.reset.title') . ' · EduCloud Lab',
            'token' => self::tokenFromQuery($request),
        ]);
    }

    /** Only app-relative paths are accepted as post-login destinations (prevents open redirects). */
    public static function safeNext(string $next): string
    {
        if (preg_match('#^/(?![/\\\\])[A-Za-z0-9/_\-.~%?=&]*$#D', $next) !== 1 || str_contains($next, '..')) {
            return '/app';
        }
        return $next;
    }

    private static function tokenFromQuery(Request $request): string
    {
        $token = $request->query['token'] ?? '';
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $token) === 1 ? $token : '';
    }
}
