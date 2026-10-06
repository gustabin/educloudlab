-- Server-side browser sessions (M2, ADR-003). The cookie holds a random 256-bit id; only its SHA-256 is stored,
-- so a database leak does not expose usable session ids. Enables "log out everywhere" on password reset.

CREATE TABLE sessions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_hash          BINARY(32)      NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    tenant_id        BIGINT UNSIGNED NOT NULL COMMENT 'Active tenant; re-validated against memberships on every request',
    ip_hash          BINARY(32)      NULL,
    user_agent       VARCHAR(255)    NULL,
    created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_activity_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    expires_at       DATETIME(3)     NOT NULL COMMENT 'Absolute lifetime limit',
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_id_hash (id_hash),
    KEY ix_sessions_user (user_id),
    KEY ix_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE,
    CONSTRAINT fk_sessions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
