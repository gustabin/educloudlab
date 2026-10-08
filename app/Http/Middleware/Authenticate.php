<?php

declare(strict_types=1);

namespace EduCloud\Http\Middleware;

use EduCloud\Core\App;
use EduCloud\Core\Auth\AuthUser;
use EduCloud\Core\Exceptions\UnauthorizedException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Modules\Auth\SessionRepository;
use EduCloud\Modules\Auth\UserRepository;

/**
 * Resolves the caller and enforces the route's 'auth' option:
 *   none    optional: a valid session (or Bearer token) is attached when present
 *   session browser session cookie required (HTML pages redirect to /login)
 *   jwt     Bearer access token required
 *   any     session or Bearer token
 *   external no user credentials are read at all: the handler authenticates the caller with its own secret
 *            (e.g. GET /metrics and METRICS_TOKEN). Never combined with a permission.
 * A request carrying a Bearer token never uses cookies (no ambient credentials → no CSRF surface).
 *
 * Attributes set: user (AuthUser|null), auth_method ('session'|'jwt'|null),
 * session (array|null incl. raw_id), jwt_tenant (tenant public id from the token, jwt only).
 */
final class Authenticate implements Middleware
{
    public function __construct(private readonly App $app, private readonly string $mode)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->mode === 'external') {
            return $next($request->withAttribute('user', null)->withAttribute('auth_method', null));
        }
        $users = new UserRepository($this->app->db());
        $cookieName = (string) $this->app->config->get('security.session.cookie', 'ecsid');
        $user = null;
        $method = null;
        $clearCookie = false;

        $bearer = $request->bearerToken();
        if ($bearer !== null) {
            if (in_array($this->mode, ['jwt', 'any', 'none'], true)) {
                $claims = $this->app->jwt()->verify($bearer);
                $row = $users->findByPublicId($claims['sub']);
                if ($row === null || $row['status'] !== 'active') {
                    throw new UnauthorizedException('Token de acceso no válido.');
                }
                $user = AuthUser::fromRow($row);
                $method = 'jwt';
                $request = $request->withAttribute('jwt_tenant', $claims['tid']);
            }
        } elseif (($raw = $request->cookies[$cookieName] ?? '') !== '') {
            $sessions = new SessionRepository($this->app->db());
            $session = strlen($raw) <= 128 ? $sessions->findLive($raw, (int) $this->app->config->get('security.session.idle_timeout')) : null;
            $row = $session === null ? null : $users->findById($session['user_id']);
            if ($session !== null && $row !== null && $row['status'] === 'active') {
                $user = AuthUser::fromRow($row);
                $method = 'session';
                if ($session['idle_seconds'] >= (int) $this->app->config->get('security.session.touch_interval', 60)) {
                    $sessions->touch($session['id']);
                }
                $request = $request->withAttribute('session', $session + ['raw_id' => $raw]);
            } else {
                if ($session !== null) {
                    $sessions->delete($session['id']);
                }
                $clearCookie = true;
            }
        }

        $request = $request->withAttribute('user', $user)->withAttribute('auth_method', $method);

        $allowed = match ($this->mode) {
            'none' => true,
            'session' => $method === 'session',
            'jwt' => $method === 'jwt',
            'any' => $method !== null,
            default => throw new \LogicException("Unknown auth mode '{$this->mode}'"),
        };

        if (!$allowed) {
            // Built here (not thrown) so a stale session cookie can still be cleared on this response.
            $e = new UnauthorizedException();
            $response = $request->isApi()
                ? Response::error(401, $e->errorCode, $e->getMessage(), [], ['request_id' => $request->requestId])
                : Response::redirect(url('/login') . '?next=' . rawurlencode($request->path));
        } else {
            $response = $next($request);
        }

        if ($clearCookie) {
            $response->withCookie($cookieName, '', -1, $request->secure);
        }
        return $response;
    }
}
