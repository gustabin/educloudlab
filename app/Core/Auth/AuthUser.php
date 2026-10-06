<?php

declare(strict_types=1);

namespace EduCloud\Core\Auth;

/** Authenticated user attached to the request by the Authenticate middleware. */
final class AuthUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $status,
        public readonly bool $isPlatformAdmin,
        public readonly string $locale = 'es',
    ) {
    }

    /** @param array<string, mixed> $row users table row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['public_id'],
            (string) $row['email'],
            (string) $row['display_name'],
            (string) $row['status'],
            (bool) $row['is_platform_admin'],
            (string) ($row['locale'] ?? 'es'),
        );
    }

    /**
     * Public representation (never includes internal ids or security fields).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->publicId,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'is_platform_admin' => $this->isPlatformAdmin,
            'locale' => $this->locale,
        ];
    }
}
