-- Educational Resource Manager: workspaces and the resource registry (M3).

CREATE TABLE workspaces (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id        CHAR(26)        NOT NULL,
    tenant_id        BIGINT UNSIGNED NOT NULL,
    owner_user_id    BIGINT UNSIGNED NOT NULL,
    name             VARCHAR(80)     NOT NULL,
    description      VARCHAR(500)    NULL,
    purpose          ENUM('general','lab') NOT NULL DEFAULT 'general',
    status           ENUM('active','deleting','deleted') NOT NULL DEFAULT 'active',
    expires_at       DATETIME(3)     NULL,
    last_activity_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_workspaces_public_id (public_id),
    UNIQUE KEY uq_workspaces_tenant_id (tenant_id, id),
    KEY ix_workspaces_tenant_owner (tenant_id, owner_user_id, status),
    KEY ix_workspaces_expiry (status, expires_at),
    CONSTRAINT fk_workspaces_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_workspaces_owner  FOREIGN KEY (owner_user_id) REFERENCES users (id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resources (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id     CHAR(26)        NOT NULL,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    workspace_id  BIGINT UNSIGNED NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    type          ENUM('storage','lakehouse','dataset','pipeline','notebook','dashboard') NOT NULL,
    name          VARCHAR(80)     NOT NULL,
    status        ENUM('provisioning','active','failed','deleting','deleted') NOT NULL DEFAULT 'provisioning',
    region        VARCHAR(40)     NOT NULL DEFAULT 'edu-local-1',
    config        JSON            NULL,
    tags          JSON            NULL,
    created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_resources_public_id (public_id),
    UNIQUE KEY uq_resources_tenant_id (tenant_id, id),
    KEY ix_resources_workspace (tenant_id, workspace_id, type, status),
    CONSTRAINT ck_resources_config CHECK (config IS NULL OR json_valid(config)),
    CONSTRAINT ck_resources_tags   CHECK (tags IS NULL OR json_valid(tags)),
    CONSTRAINT fk_resources_workspace FOREIGN KEY (tenant_id, workspace_id)
        REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_resources_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
