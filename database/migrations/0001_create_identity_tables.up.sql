-- Identity: tenants, users, memberships (M2/M3).

CREATE TABLE tenants (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id     CHAR(26)        NOT NULL,
    type          ENUM('personal','organization') NOT NULL,
    name          VARCHAR(120)    NOT NULL,
    slug          VARCHAR(80)     NOT NULL,
    status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
    quota_profile VARCHAR(40)     NOT NULL DEFAULT 'default',
    created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_public_id (public_id),
    UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id          CHAR(26)        NOT NULL,
    email              VARCHAR(254)    NOT NULL,
    password_hash      VARCHAR(255)    NOT NULL,
    display_name       VARCHAR(100)    NOT NULL,
    locale             VARCHAR(10)     NOT NULL DEFAULT 'es',
    status             ENUM('pending','active','locked','disabled') NOT NULL DEFAULT 'pending',
    is_platform_admin  TINYINT(1)      NOT NULL DEFAULT 0,
    email_verified_at  DATETIME(3)     NULL,
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until       DATETIME(3)     NULL,
    last_login_at      DATETIME(3)     NULL,
    created_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_public_id (public_id),
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT ck_users_platform_admin CHECK (is_platform_admin IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE memberships (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    role       ENUM('org_admin','instructor','student','read_only') NOT NULL,
    status     ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_memberships_tenant_user (tenant_id, user_id),
    KEY ix_memberships_user (user_id, status),
    CONSTRAINT fk_memberships_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_memberships_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
