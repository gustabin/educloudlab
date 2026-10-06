<?php

declare(strict_types=1);

namespace EduCloud\Modules\Auth;

use EduCloud\Core\App;
use EduCloud\Core\Auth\AuthUser;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Tenants\TenantRepository;

/** REST endpoints under /api/v1/auth. Thin: validate → service → envelope. */
final class AuthController
{
    private AuthService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new AuthService($app);
    }

    public function register(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'max:1024'],
            'display_name' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[^<>\x00-\x1F]+$/u'],
        ]);
        $this->service->register($request, $data['email'], $request->json()['password'], $data['display_name']);
        return $this->accepted($request, 'Si los datos son válidos, recibirás un correo para confirmar tu cuenta.');
    }

    public function verifyEmail(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $this->service->verifyEmail($request, $data['token'], (string) $request->json()['password']);
        return Response::json(null, 200, 'Correo confirmado. Ya puedes iniciar sesión.', $this->meta($request));
    }

    public function resendVerification(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['email' => ['required', 'email']]);
        $this->service->resendVerification($request, $data['email']);
        return $this->accepted($request, 'Si la cuenta existe y está pendiente de confirmar, te enviaremos un nuevo enlace.');
    }

    public function login(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $result = $this->service->login($request, $data['email'], $request->json()['password']);

        $response = Response::json([
            'user' => $result['user']->toArray(),
            'csrf_token' => $this->app->csrf()->forSession($result['session_id']),
        ], 200, 'Sesión iniciada.', $this->meta($request));
        return $response->withCookie(
            (string) $this->app->config->get('security.session.cookie'),
            $result['session_id'],
            0,
            $request->secure
        );
    }

    public function logout(Request $request): Response
    {
        $this->service->logout($request);
        return Response::noContent()->withCookie((string) $this->app->config->get('security.session.cookie'), '', -1, $request->secure);
    }

    public function me(Request $request): Response
    {
        /** @var AuthUser $user */
        $user = $request->attribute('user');
        /** @var TenantContext $tenant */
        $tenant = $request->attribute('tenant');
        $memberships = (new TenantRepository($this->app->db()))->listActiveMemberships($user->id);

        return Response::json([
            'user' => $user->toArray(),
            'auth_method' => $request->attribute('auth_method'),
            'tenant' => [
                'id' => $tenant->tenantPublicId,
                'name' => $request->attribute('tenant_name'),
                'type' => $tenant->tenantType,
                'role' => $tenant->role,
            ],
            'memberships' => array_map(static fn (array $m): array => [
                'tenant_id' => $m['tenant_public_id'],
                'name' => $m['tenant_name'],
                'type' => $m['tenant_type'],
                'role' => $m['role'],
            ], $memberships),
        ], 200, '', $this->meta($request));
    }

    public function forgotPassword(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['email' => ['required', 'email']]);
        $this->service->forgotPassword($request, $data['email']);
        return $this->accepted($request, 'Si existe una cuenta con ese correo, recibirás un enlace para restablecer la contraseña.');
    }

    public function resetPassword(Request $request): Response
    {
        Validator::validate($request->json(), [
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $body = $request->json();
        $this->service->resetPassword($request, (string) $body['token'], (string) $body['password']);
        return Response::json(null, 200, 'Contraseña actualizada. Se cerraron todas tus sesiones; inicia sesión de nuevo.', $this->meta($request));
    }

    public function issueTokens(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $pair = $this->service->issueTokens($request, $data['email'], $request->json()['password']);
        return Response::json($pair, 201, '', $this->meta($request));
    }

    public function refreshTokens(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['refresh_token' => ['required', 'string', 'max:128']]);
        return Response::json($this->service->refreshTokens($request, $data['refresh_token']), 200, '', $this->meta($request));
    }

    public function revokeTokens(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['refresh_token' => ['required', 'string', 'max:128']]);
        $this->service->revokeTokens($request, $data['refresh_token']);
        return Response::noContent();
    }

    private function accepted(Request $request, string $message): Response
    {
        return Response::json(null, 202, $message, $this->meta($request));
    }

    /** @return array<string, string> */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->requestId];
    }
}
