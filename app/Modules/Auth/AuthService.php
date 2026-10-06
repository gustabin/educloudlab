<?php

declare(strict_types=1);

namespace EduCloud\Modules\Auth;

use EduCloud\Core\App;
use EduCloud\Core\Auth\AuthUser;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\UnauthorizedException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Request;
use EduCloud\Modules\Email\EmailService;
use EduCloud\Modules\Tenants\TenantRepository;

/**
 * Account lifecycle: registration, email verification, login/logout, password reset, API tokens.
 * Anti-enumeration: register / forgot / resend always answer the same way; login failures share one message.
 */
final class AuthService
{
    private const INVALID_CREDENTIALS = 'Correo o contraseña incorrectos, o la cuenta está bloqueada temporalmente.';

    private UserRepository $users;
    private TokenRepository $tokens;
    private SessionRepository $sessions;
    private TenantRepository $tenants;
    private EmailService $email;
    private PasswordPolicy $policy;

    public function __construct(private readonly App $app)
    {
        $db = $app->db();
        $this->users = new UserRepository($db);
        $this->tokens = new TokenRepository($db);
        $this->sessions = new SessionRepository($db);
        $this->tenants = new TenantRepository($db);
        $this->email = new EmailService($db);
        $this->policy = new PasswordPolicy(
            (int) $app->config->get('security.password.min_length', 12),
            (int) $app->config->get('security.password.max_length', 128),
        );
    }

    public function register(Request $request, string $email, string $password, string $displayName): void
    {
        $this->policy->assertAcceptable($password, $email);
        $this->app->rateLimiter()->hit('email_account', 'email|' . $email);

        // Always hash, so timing does not reveal whether the address is already registered.
        $hash = PasswordPolicy::hash($password);
        $existing = $this->users->findByEmail($email);

        if ($existing !== null && $existing['status'] === 'pending') {
            // Nobody has proven ownership of this address yet: the newest registration replaces the old
            // credentials and invalidates older verification links (prevents pre-registration takeover).
            $userId = (int) $existing['id'];
            $this->users->replacePendingCredentials($userId, $hash, $displayName);
            $this->queueVerification($userId, $email);
            $this->app->audit()->record($request, 'auth.register', 'success', null, $userId, meta: ['reason' => 'pending_replaced']);
            return;
        }

        if ($existing !== null) {
            if ($existing['status'] !== 'disabled') {
                // Sent to the real owner; contains no attacker-controlled text.
                $this->email->queue('account_exists', $email, ['url' => $this->app->config->get('app.url') . '/login'], (int) $existing['id']);
            }
            $this->app->audit()->record($request, 'auth.register', 'denied', null, (int) $existing['id'], meta: ['reason' => 'email_exists']);
            return;
        }

        [$userId, $tenantId] = $this->app->db()->transaction(function () use ($email, $hash, $displayName): array {
            $userId = $this->users->create($email, $hash, $displayName, 'es');
            $tenantId = $this->tenants->createPersonalTenant($userId, $displayName);
            return [$userId, $tenantId];
        });
        $this->queueVerification($userId, $email);
        $this->app->audit()->record($request, 'auth.register', 'success', $tenantId, $userId);
    }

    /**
     * Confirms the address. Requires the account password as well as the emailed token, so neither
     * someone who only knows the password (pre-registration attacker) nor someone who only has the
     * link can activate the account. A wrong password does not consume the token.
     */
    public function verifyEmail(Request $request, string $token, string $password): void
    {
        $found = $this->tokens->findValidOneTime($token, 'email_verify');
        if ($found === null) {
            $this->app->audit()->record($request, 'auth.verify_email', 'failure');
            throw new ApiException(422, 'TOKEN_INVALID', 'El enlace de verificación no es válido o ha caducado. Solicita uno nuevo.');
        }
        $this->app->rateLimiter()->hit('login_account', 'email|' . $found['email'] . '|ip|' . $request->ip);
        if (!password_verify($password, $found['password_hash'])) {
            $this->app->audit()->record($request, 'auth.verify_email', 'failure', null, $found['user_id'], meta: ['reason' => 'bad_password']);
            throw new ValidationException([[
                'field' => 'password',
                'code' => 'invalid',
                'message' => 'La contraseña no coincide con la que usaste al registrarte. Si no la recuerdas, restablécela.',
            ]]);
        }
        $userId = $this->tokens->consumeOneTime($token, 'email_verify');
        if ($userId === null) {
            throw new ApiException(422, 'TOKEN_INVALID', 'El enlace de verificación no es válido o ha caducado. Solicita uno nuevo.');
        }
        $this->users->markEmailVerified($userId);
        $this->app->audit()->record($request, 'auth.verify_email', 'success', null, $userId);
    }

    private function queueVerification(int $userId, string $email): void
    {
        $ttl = (int) $this->app->config->get('security.tokens.email_verify_ttl');
        $token = $this->tokens->createOneTime($userId, 'email_verify', $ttl);
        // No display name: before verification it is attacker-controllable text.
        $this->email->queue('verify_email', $email, [
            'url' => $this->app->config->get('app.url') . '/verify-email?token=' . rawurlencode($token),
        ], $userId);
    }

    public function resendVerification(Request $request, string $email): void
    {
        $this->app->rateLimiter()->hit('email_account', 'email|' . $email);
        $user = $this->users->findByEmail($email);
        if ($user === null || $user['status'] !== 'pending') {
            return;
        }
        $this->queueVerification((int) $user['id'], $email);
        $this->app->audit()->record($request, 'auth.verify_email_resend', 'success', null, (int) $user['id']);
    }

    /**
     * Browser login. Returns the user and the raw session id for the cookie.
     *
     * @return array{user: AuthUser, session_id: string, tenant_id: int}
     */
    public function login(Request $request, string $email, string $password): array
    {
        $user = $this->authenticateCredentials($request, $email, $password, 'auth.login');
        $membership = $this->tenants->findDefaultMembership($user->id);
        if ($membership === null) {
            throw new ForbiddenException('No perteneces a ninguna organización activa.');
        }
        $previous = $request->attribute('session');
        if (is_array($previous)) {
            $this->sessions->delete((int) $previous['id']);
        }
        $sessionId = $this->sessions->create(
            $user->id,
            $membership['tenant_id'],
            hash_hmac('sha256', 'ip|' . $request->ip, (string) $this->app->config->get('app.hash_key'), true),
            $request->header('user-agent'),
            (int) $this->app->config->get('security.session.absolute_lifetime'),
        );
        $this->app->audit()->record($request, 'auth.login', 'success', $membership['tenant_id'], $user->id);
        return ['user' => $user, 'session_id' => $sessionId, 'tenant_id' => $membership['tenant_id']];
    }

    public function logout(Request $request): void
    {
        $session = $request->attribute('session');
        $user = $request->attribute('user');
        if (is_array($session)) {
            $this->sessions->delete((int) $session['id']);
        }
        $this->app->audit()->record($request, 'auth.logout', 'success', null, $user instanceof AuthUser ? $user->id : null);
    }

    public function forgotPassword(Request $request, string $email): void
    {
        $this->app->rateLimiter()->hit('email_account', 'email|' . $email);
        $user = $this->users->findByEmail($email);
        if ($user === null || !in_array($user['status'], ['active', 'pending', 'locked'], true)) {
            return;
        }
        $ttl = (int) $this->app->config->get('security.tokens.password_reset_ttl');
        $token = $this->tokens->createOneTime((int) $user['id'], 'password_reset', $ttl);
        $this->email->queue('password_reset', $email, [
            'display_name' => (string) $user['display_name'],
            'url' => $this->app->config->get('app.url') . '/reset-password?token=' . rawurlencode($token),
        ], (int) $user['id']);
        $this->app->audit()->record($request, 'auth.password_forgot', 'success', null, (int) $user['id']);
    }

    public function resetPassword(Request $request, string $token, string $password): void
    {
        // Validate the password before consuming the token, so a rejected password does not burn the link.
        $found = $this->tokens->findValidOneTime($token, 'password_reset');
        if ($found !== null) {
            $this->policy->assertAcceptable($password, $found['email']);
        }

        $userId = $this->tokens->consumeOneTime($token, 'password_reset');
        if ($userId === null) {
            $this->app->audit()->record($request, 'auth.password_reset', 'failure');
            throw new ApiException(422, 'TOKEN_INVALID', 'El enlace para restablecer la contraseña no es válido o ha caducado. Solicita uno nuevo.');
        }

        $this->app->db()->transaction(function () use ($userId, $password): void {
            $this->users->updatePasswordHash($userId, PasswordPolicy::hash($password));
            $this->users->markEmailVerified($userId); // the reset link proves ownership of the address
            $this->users->clearLockout($userId);
            $this->sessions->deleteAllForUser($userId);
            $this->tokens->revokeAllForUser($userId);
        });

        $user = $this->users->findById($userId);
        if ($user !== null) {
            $this->email->queue('password_changed', (string) $user['email'], [
                'display_name' => (string) $user['display_name'],
                'url' => $this->app->config->get('app.url') . '/login',
            ], $userId);
        }
        $this->app->audit()->record($request, 'auth.password_reset', 'success', null, $userId);
    }

    /**
     * Issues an access + refresh token pair for API clients.
     *
     * @return array<string, mixed>
     */
    public function issueTokens(Request $request, string $email, string $password): array
    {
        $user = $this->authenticateCredentials($request, $email, $password, 'auth.token');
        $membership = $this->tenants->findDefaultMembership($user->id);
        if ($membership === null) {
            throw new ForbiddenException('No perteneces a ninguna organización activa.');
        }
        $pair = $this->tokenPair($user, $membership['tenant_id'], $membership['tenant_public_id'], random_bytes(16), null);
        $this->app->audit()->record($request, 'auth.token_issue', 'success', $membership['tenant_id'], $user->id);
        return $pair;
    }

    /**
     * Rotates a refresh token. Reuse of an already-used or revoked token revokes the whole family.
     *
     * @return array<string, mixed>
     */
    public function refreshTokens(Request $request, string $refreshToken): array
    {
        $row = $this->tokens->findRefresh($refreshToken);
        if ($row === null) {
            throw new UnauthorizedException('Token de refresco no válido.');
        }
        $familyId = (string) $row['family_id'];
        if ($row['used_at'] !== null || $row['revoked_at'] !== null || !$this->tokens->markRefreshUsed((int) $row['id'])) {
            $this->tokens->revokeFamily($familyId);
            $this->app->audit()->record(
                $request,
                'auth.token_refresh',
                'denied',
                (int) $row['tenant_id'],
                (int) $row['user_id'],
                meta: ['reason' => 'reuse_detected']
            );
            throw new UnauthorizedException('Token de refresco no válido.');
        }
        if ((int) $row['expired'] === 1) {
            throw new UnauthorizedException('Token de refresco caducado.');
        }

        $userRow = $this->users->findById((int) $row['user_id']);
        $membership = $userRow === null ? null : $this->tenants->findActiveMembership((int) $userRow['id'], (int) $row['tenant_id']);
        if ($userRow === null || $userRow['status'] !== 'active' || $membership === null) {
            $this->tokens->revokeFamily($familyId);
            throw new UnauthorizedException('Token de refresco no válido.');
        }
        return $this->tokenPair(
            AuthUser::fromRow($userRow),
            $membership['tenant_id'],
            $membership['tenant_public_id'],
            $familyId,
            (int) $row['id']
        );
    }

    public function revokeTokens(Request $request, string $refreshToken): void
    {
        $row = $this->tokens->findRefresh($refreshToken);
        if ($row !== null) {
            $this->tokens->revokeFamily((string) $row['family_id']);
            $this->app->audit()->record($request, 'auth.token_revoke', 'success', (int) $row['tenant_id'], (int) $row['user_id']);
        }
    }

    /** Shared credential check with lockout, rehash and anti-enumeration. */
    private function authenticateCredentials(Request $request, string $email, string $password, string $action): AuthUser
    {
        // Keyed by (email, IP): an attacker elsewhere cannot exhaust the legitimate user's own allowance.
        $this->app->rateLimiter()->hit('login_account', 'email|' . $email . '|ip|' . $request->ip);
        $row = $this->users->findByEmail($email);

        if ($row === null) {
            password_verify($password, PasswordPolicy::DUMMY_HASH);
            $this->app->audit()->record($request, $action, 'failure', meta: ['reason' => 'unknown_account']);
            throw new ApiException(401, 'INVALID_CREDENTIALS', self::INVALID_CREDENTIALS);
        }
        $userId = (int) $row['id'];

        if ($this->users->isLocked($userId)) {
            password_verify($password, PasswordPolicy::DUMMY_HASH);
            $this->app->audit()->record($request, $action, 'denied', null, $userId, meta: ['reason' => 'locked']);
            throw new ApiException(401, 'INVALID_CREDENTIALS', self::INVALID_CREDENTIALS);
        }

        if (!password_verify($password, (string) $row['password_hash'])) {
            $failures = $this->users->recordFailedLogin($userId);
            $lockSeconds = 0;
            foreach ((array) $this->app->config->get('security.lockout', []) as $threshold => $seconds) {
                if ($failures >= (int) $threshold) {
                    $lockSeconds = (int) $seconds;
                }
            }
            if ($lockSeconds > 0) {
                $this->users->lockUntil($userId, $lockSeconds);
                if (array_key_exists($failures, (array) $this->app->config->get('security.lockout', []))) {
                    // Tell the owner, with a way out that does not depend on waiting (password reset).
                    $this->email->queue('account_locked', (string) $row['email'], [
                        'display_name' => (string) $row['display_name'],
                        'url' => $this->app->config->get('app.url') . '/forgot-password',
                    ], $userId);
                }
            }
            $meta = ['reason' => 'bad_password', 'failures' => $failures];
            $this->app->audit()->record($request, $action, 'failure', null, $userId, meta: $meta);
            throw new ApiException(401, 'INVALID_CREDENTIALS', self::INVALID_CREDENTIALS);
        }

        // Correct password: account state may now be disclosed to the legitimate owner.
        if ($row['status'] === 'pending') {
            throw new ApiException(
                403,
                'EMAIL_NOT_VERIFIED',
                'Confirma tu correo electrónico antes de iniciar sesión. Revisa tu bandeja de entrada.'
            );
        }
        if ($row['status'] !== 'active') {
            $this->app->audit()->record($request, $action, 'denied', null, $userId, meta: ['reason' => 'status_' . $row['status']]);
            throw new ApiException(403, 'ACCOUNT_DISABLED', 'Esta cuenta está desactivada. Contacta con el administrador.');
        }

        if (PasswordPolicy::needsRehash((string) $row['password_hash'])) {
            $this->users->updatePasswordHash($userId, PasswordPolicy::hash($password));
        }
        $this->users->recordSuccessfulLogin($userId);
        $this->app->rateLimiter()->clear('login_account', 'email|' . $email . '|ip|' . $request->ip);
        return AuthUser::fromRow($row);
    }

    /** @return array<string, mixed> */
    private function tokenPair(AuthUser $user, int $tenantId, string $tenantPublicId, string $familyId, ?int $rotatedFromId): array
    {
        $access = $this->app->jwt()->issue($user->publicId, $tenantPublicId);
        $refreshTtl = (int) $this->app->config->get('security.jwt.refresh_ttl');
        $refresh = $this->tokens->createRefresh($user->id, $tenantId, $familyId, $rotatedFromId, $refreshTtl);
        return [
            'token_type' => 'Bearer',
            'access_token' => $access['token'],
            'expires_in' => $access['expires_in'],
            'refresh_token' => $refresh,
            'refresh_expires_in' => $refreshTtl,
        ];
    }
}
