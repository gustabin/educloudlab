-- Datasets, versions, execution jobs and SQL history (M4/M5).
-- The current version of a dataset is its highest version_no with status 'ready' (no back-reference FK).
-- Lineage between datasets is deferred to a dedicated dataset_lineage table (release 1.1).

CREATE TABLE datasets (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    resource_id        BIGINT UNSIGNED NOT NULL,
    workspace_id       BIGINT UNSIGNED NOT NULL,
    layer              ENUM('raw','bronze','silver','gold') NOT NULL,
    table_name         VARCHAR(63)     NULL,
    created_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_datasets_resource (resource_id),
    UNIQUE KEY uq_datasets_tenant_id (tenant_id, id),
    UNIQUE KEY uq_datasets_workspace_table (workspace_id, layer, table_name),
    KEY ix_datasets_tenant_workspace (tenant_id, workspace_id, layer),
    CONSTRAINT ck_datasets_table_name CHECK (table_name IS NULL OR table_name REGEXP '^[a-z][a-z0-9_]{0,62}$'),
    CONSTRAINT fk_datasets_resource FOREIGN KEY (tenant_id, resource_id)
        REFERENCES resources (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_datasets_workspace FOREIGN KEY (tenant_id, workspace_id)
        REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dataset_versions (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id          CHAR(26)        NOT NULL,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    dataset_id         BIGINT UNSIGNED NOT NULL,
    version_no         INT UNSIGNED    NOT NULL,
    format             ENUM('csv','json','parquet','table') NOT NULL,
    storage_key        CHAR(26)        NULL,
    original_name      VARCHAR(255)    NULL,
    bytes              BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256             BINARY(32)      NULL,
    row_count          BIGINT UNSIGNED NULL,
    column_count       INT UNSIGNED    NULL,
    schema_json        JSON            NULL,
    status             ENUM('pending','processing','ready','failed') NOT NULL DEFAULT 'pending',
    error_code         VARCHAR(40)     NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_dataset_versions_public_id (public_id),
    UNIQUE KEY uq_dataset_versions_tenant_id (tenant_id, id),
    UNIQUE KEY uq_dataset_versions_no (dataset_id, version_no),
    UNIQUE KEY uq_dataset_versions_storage_key (storage_key),
    CONSTRAINT ck_dataset_versions_schema CHECK (schema_json IS NULL OR json_valid(schema_json)),
    CONSTRAINT fk_dataset_versions_dataset FOREIGN KEY (tenant_id, dataset_id)
        REFERENCES datasets (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_dataset_versions_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id        CHAR(26)        NOT NULL,
    tenant_id        BIGINT UNSIGNED NOT NULL,
    workspace_id     BIGINT UNSIGNED NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    type             ENUM('ingest','sql_query','transform','validate','pipeline_run','cleanup') NOT NULL,
    status           ENUM('queued','running','succeeded','failed','cancelled','timed_out') NOT NULL DEFAULT 'queued',
    priority         TINYINT UNSIGNED NOT NULL DEFAULT 5,
    payload          JSON            NOT NULL,
    result_key       CHAR(26)        NULL,
    result_summary   JSON            NULL,
    error_code       VARCHAR(40)     NULL,
    safe_message     VARCHAR(500)    NULL,
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    timeout_s        SMALLINT UNSIGNED NOT NULL,
    cancel_requested TINYINT(1)      NOT NULL DEFAULT 0,
    locked_by        VARCHAR(64)     NULL,
    queued_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    started_at       DATETIME(3)     NULL,
    heartbeat_at     DATETIME(3)     NULL,
    finished_at      DATETIME(3)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jobs_public_id (public_id),
    UNIQUE KEY uq_jobs_tenant_id (tenant_id, id),
    KEY ix_jobs_queue (status, priority, queued_at),
    KEY ix_jobs_tenant_user (tenant_id, user_id, status),
    KEY ix_jobs_workspace (workspace_id, status),
    CONSTRAINT ck_jobs_payload CHECK (json_valid(payload)),
    CONSTRAINT ck_jobs_result_summary CHECK (result_summary IS NULL OR json_valid(result_summary)),
    CONSTRAINT fk_jobs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_jobs_workspace FOREIGN KEY (tenant_id, workspace_id)
        REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE query_history (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id    CHAR(26)        NOT NULL,
    tenant_id    BIGINT UNSIGNED NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    job_id       BIGINT UNSIGNED NULL COMMENT 'Soft link to jobs.id (no FK: jobs are purged independently)',
    sql_text     MEDIUMTEXT      NOT NULL,
    status       ENUM('queued','succeeded','failed','cancelled','timed_out') NOT NULL DEFAULT 'queued',
    duration_ms  INT UNSIGNED    NULL,
    row_count    INT UNSIGNED    NULL,
    error_code   VARCHAR(40)     NULL,
    created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_query_history_public_id (public_id),
    KEY ix_query_history_user (tenant_id, user_id, created_at),
    KEY ix_query_history_workspace (tenant_id, workspace_id, created_at),
    CONSTRAINT fk_query_history_workspace FOREIGN KEY (tenant_id, workspace_id)
        REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_query_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
