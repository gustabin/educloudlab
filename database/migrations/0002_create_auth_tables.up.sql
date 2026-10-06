-- Authentication support: one-time tokens, JWT refresh tokens, rate limiting (M2).
-- Tokens are stored only as SHA-256 hashes.

CREATE TABLE auth_tokens (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    type       ENUM('email_verify','password_reset') NOT NULL,
    token_hash BINARY(32)      NOT NULL,
    expires_at DATETIME(3)     NOT NULL,
    used_at    DATETIME(3)     NULL,
    created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY ix_auth_tokens_user_type (user_id, type),
    KEY ix_auth_tokens_expires (expires_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refresh_tokens (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    family_id       BINARY(16)      NOT NULL,
    token_hash      BINARY(32)      NOT NULL,
    rotated_from_id BIGINT UNSIGNED NULL,
    expires_at      DATETIME(3)     NOT NULL,
    used_at         DATETIME(3)     NULL,
    revoked_at      DATETIME(3)     NULL,
    created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_refresh_tokens_hash (token_hash),
    KEY ix_refresh_tokens_family (family_id),
    KEY ix_refresh_tokens_user (user_id, revoked_at),
    KEY ix_refresh_tokens_expires (expires_at),
    CONSTRAINT fk_refresh_tokens_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE,
    CONSTRAINT fk_refresh_tokens_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- key_hash = HMAC(APP_HASH_KEY, policy + subject); no raw IPs or emails stored.
CREATE TABLE rate_limits (
    key_hash     BINARY(32)   NOT NULL,
    window_start DATETIME     NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (key_hash, window_start),
    KEY ix_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
