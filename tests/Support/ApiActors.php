<?php

declare(strict_types=1);

namespace EduCloud\Tests\Support;

use EduCloud\Core\Response;
use EduCloud\Core\Ulid;

/**
 * Multi-user helpers: act as different users with Bearer tokens (no cookies involved),
 * and build organization tenants with members. Requires AuthHelpers + EduCloud\Tests\TestCase.
 */
trait ApiActors
{
    /** @var array<string, string> email => access token */
    private array $tokens = [];

    /** Registers + verifies a user and returns a Bearer access token for its default (personal) tenant. */
    protected function actor(string $email): string
    {
        if (!isset($this->tokens[$email])) {
            // Register like an anonymous visitor, without disturbing a browser session the test may hold.
            $jar = $this->cookieJar;
            $this->cookieJar = [];
            try {
                if ($this->app()->db()->scalar('SELECT id FROM users WHERE email = ?', [$email]) === null) {
                    $this->createVerifiedUser($email);
                }
                $this->tokens[$email] = (string) $this->issueTokens($email)['access_token'];
            } finally {
                $this->cookieJar = $jar;
            }
        }
        return $this->tokens[$email];
    }

    /** @param array<string, mixed>|null $json */
    protected function as(string $token, string $method, string $path, ?array $json = null): Response
    {
        $jar = $this->cookieJar;
        $this->cookieJar = [];
        try {
            return $this->request($method, $path, $json, ['Authorization' => 'Bearer ' . $token]);
        } finally {
            $this->cookieJar = $jar;
        }
    }

    protected function userId(string $email): int
    {
        return (int) $this->app()->db()->scalar('SELECT id FROM users WHERE email = ?', [$email]);
    }

    /** @return array{id: int, public_id: string} */
    protected function createOrgTenant(string $name): array
    {
        $publicId = Ulid::generate();
        $id = $this->app()->db()->insert(
            "INSERT INTO tenants (public_id, type, name, slug) VALUES (?, 'organization', ?, ?)",
            [$publicId, $name, 'org-' . strtolower($publicId)]
        );
        return ['id' => $id, 'public_id' => $publicId];
    }

    protected function addMember(int $tenantId, string $email, string $role): void
    {
        $this->app()->db()->insert(
            'INSERT INTO memberships (tenant_id, user_id, role) VALUES (?, ?, ?)',
            [$tenantId, $this->userId($email), $role]
        );
    }

    /** Logs in with a browser session and switches it to $tenantPublicId. Returns the session CSRF token. */
    protected function sessionIn(string $email, string $tenantPublicId): string
    {
        $this->cookieJar = [];
        $csrf = $this->login($email);
        $r = $this->request('POST', '/api/v1/tenants/' . $tenantPublicId . '/switch', null, ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $r->status, $r->body);
        return $csrf;
    }

    /** @return array<string, mixed> created workspace */
    protected function createWorkspace(string $token, string $name = 'Proyecto Retail'): array
    {
        $r = $this->as($token, 'POST', '/api/v1/workspaces', ['name' => $name, 'description' => 'Datos de ventas']);
        self::assertSame(201, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    /** @return array<string, mixed> created resource */
    protected function createResource(string $token, string $workspaceId, string $type = 'storage', string $name = 'raw-files'): array
    {
        $r = $this->as($token, 'POST', "/api/v1/workspaces/$workspaceId/resources", ['type' => $type, 'name' => $name]);
        self::assertSame(201, $r->status, $r->body);
        return $r->decoded()['data'];
    }
}
