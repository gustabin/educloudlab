<?php

declare(strict_types=1);

namespace EduCloud\Http\Middleware;

use EduCloud\Core\App;
use EduCloud\Core\Csrf;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;

/**
 * CSRF defence for cookie-authenticated (and anonymous form) requests.
 *  - Safe methods on HTML pages: ensures the anonymous CSRF cookie exists so forms can get a token.
 *  - Unsafe methods: requires a valid X-CSRF-Token (or csrf_token field) bound to the session, or to the
 *    anonymous cookie when there is no session; also rejects a foreign Origin header.
 *  - Skipped for verified Bearer (JWT) requests and for routes declared with 'csrf' => false
 *    (credential-exchange endpoints that never read cookies).
 */
final class CsrfProtection implements Middleware
{
    public function __construct(private readonly App $app, private readonly bool $enabled)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $anon = (string) ($request->cookies[Csrf::ANON_COOKIE] ?? '');
        $newAnon = null;
        if ($anon === '' || strlen($anon) > 64) {
            $newAnon = Csrf::newAnonymousValue();
            $anon = $newAnon;
        }
        $request = $request->withAttribute('csrf_anon', $anon);

        if ($request->isUnsafeMethod() && $this->enabled && $request->attribute('auth_method') !== 'jwt') {
            $this->assertSameOrigin($request);
            $provided = (string) ($request->header('x-csrf-token') ?? ($request->post['csrf_token'] ?? ''));
            $session = $request->attribute('session');
            $csrf = $this->app->csrf();
            $expected = is_array($session)
                ? $csrf->forSession((string) $session['raw_id'])
                : ($newAnon === null ? $csrf->forAnonymous($anon) : '');
            if ($expected === '' || !$csrf->verify($expected, $provided)) {
                throw new ApiException(403, 'CSRF_INVALID', 'La sesión del formulario caducó. Recarga la página e inténtalo de nuevo.');
            }
        }

        $response = $next($request);

        // Only HTML pages need to hand out the anonymous token; never set cookies on API responses.
        if ($newAnon !== null && !$request->isApi() && !$request->isUnsafeMethod()) {
            $response->withCookie(Csrf::ANON_COOKIE, $newAnon, 0, $request->secure);
        }
        return $response;
    }

    private function assertSameOrigin(Request $request): void
    {
        $origin = $request->header('origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            return; // Not sent by all browsers/clients; the token check still applies.
        }
        $app = parse_url((string) $this->app->config->get('app.url'));
        $expected = strtolower(($app['scheme'] ?? 'http') . '://' . ($app['host'] ?? '') . (isset($app['port']) ? ':' . $app['port'] : ''));
        if (strtolower(rtrim($origin, '/')) !== $expected) {
            throw new ApiException(403, 'CSRF_INVALID', 'Origen de la solicitud no permitido.');
        }
    }
}
