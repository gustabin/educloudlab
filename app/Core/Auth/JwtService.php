<?php

declare(strict_types=1);

namespace EduCloud\Core\Auth;

use EduCloud\Core\Config;
use EduCloud\Core\Exceptions\UnauthorizedException;
use EduCloud\Core\Ulid;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Short-lived access tokens for API clients (ADR-003).
 * HS256 only (algorithm pinned per key, never taken from the token header), kid-based key ring for rotation,
 * issuer/audience verified, minimal claims: sub = user public id, tid = tenant public id.
 */
final class JwtService
{
    private const ALG = 'HS256';

    /** @var array<string, string> kid => raw key bytes */
    private array $keys = [];
    private string $signingKid = '';

    public function __construct(private readonly Config $config)
    {
        foreach (array_filter(array_map('trim', explode(',', (string) $config->get('security.jwt.keys')))) as $entry) {
            [$kid, $b64] = array_pad(explode(':', $entry, 2), 2, '');
            $raw = base64_decode($b64, true);
            if ($kid === '' || $raw === false || strlen($raw) < 32) {
                throw new \RuntimeException('Invalid JWT_KEYS entry (expected kid:base64 with >= 32 bytes)');
            }
            $this->keys[$kid] = $raw;
            if ($this->signingKid === '') {
                $this->signingKid = $kid;
            }
        }
        $issuer = (string) $config->get('security.jwt.issuer');
        $audience = (string) $config->get('security.jwt.audience');
        if ($this->signingKid !== '' && ($issuer === '' || $audience === '')) {
            throw new \RuntimeException('JWT_ISSUER and JWT_AUDIENCE are required when JWT_KEYS is set');
        }
    }

    public function isConfigured(): bool
    {
        return $this->signingKid !== '';
    }

    /** @return array{token: string, expires_in: int} */
    public function issue(string $userPublicId, string $tenantPublicId): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('JWT keys are not configured');
        }
        $ttl = (int) $this->config->get('security.jwt.access_ttl', 900);
        $now = time();
        $claims = [
            'iss' => (string) $this->config->get('security.jwt.issuer'),
            'aud' => (string) $this->config->get('security.jwt.audience'),
            'sub' => $userPublicId,
            'tid' => $tenantPublicId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => Ulid::generate(),
        ];
        return ['token' => JWT::encode($claims, $this->keys[$this->signingKid], self::ALG, $this->signingKid), 'expires_in' => $ttl];
    }

    /**
     * Verifies signature, expiry, issuer and audience.
     *
     * @return array{sub: string, tid: string, jti: string}
     */
    public function verify(string $token): array
    {
        if (!$this->isConfigured() || substr_count($token, '.') !== 2) {
            throw new UnauthorizedException('Token de acceso no válido.');
        }
        $keyObjects = [];
        foreach ($this->keys as $kid => $raw) {
            $keyObjects[$kid] = new Key($raw, self::ALG);
        }
        try {
            JWT::$leeway = (int) $this->config->get('security.jwt.leeway', 30);
            $claims = (array) JWT::decode($token, $keyObjects);
        } catch (Throwable) {
            throw new UnauthorizedException('Token de acceso no válido o caducado.');
        }

        $aud = $claims['aud'] ?? null;
        $audOk = is_array($aud)
            ? in_array($this->config->get('security.jwt.audience'), $aud, true)
            : $aud === $this->config->get('security.jwt.audience');
        if (($claims['iss'] ?? null) !== $this->config->get('security.jwt.issuer') || !$audOk) {
            throw new UnauthorizedException('Token de acceso no válido.');
        }
        if (!is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null)) {
            throw new UnauthorizedException('Token de acceso no válido.');
        }
        foreach (['sub', 'tid', 'jti'] as $claim) {
            if (!isset($claims[$claim]) || !is_string($claims[$claim]) || !Ulid::isValid($claims[$claim])) {
                throw new UnauthorizedException('Token de acceso no válido.');
            }
        }
        return ['sub' => $claims['sub'], 'tid' => $claims['tid'], 'jti' => $claims['jti']];
    }
}
