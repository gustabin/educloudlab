-- M7 (release 1.1): pipelines and their runs, dataset lineage, object storage (containers + objects).

-- A pipeline is a resource of type 'pipeline' (API id = resource public id); the definition is a validated JSON chain.
CREATE TABLE pipelines (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED  NOT NULL,
    workspace_id  BIGINT UNSIGNED  NOT NULL,
    resource_id   BIGINT UNSIGNED  NOT NULL,
    definition    JSON             NOT NULL,
    version       INT UNSIGNED     NOT NULL DEFAULT 1,
    created_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_pipelines_resource (resource_id),
    UNIQUE KEY uq_pipelines_tenant_id (tenant_id, id),
    KEY ix_pipelines_workspace (tenant_id, workspace_id),
    CONSTRAINT ck_pipelines_definition CHECK (json_valid(definition)),
    CONSTRAINT fk_pipelines_resource FOREIGN KEY (tenant_id, resource_id) REFERENCES resources (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pipelines_workspace FOREIGN KEY (tenant_id, workspace_id) REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- definition: snapshot executed by the run (later edits of the pipeline do not change past runs).
CREATE TABLE pipeline_runs (
    id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    public_id          CHAR(26)         NOT NULL,
    tenant_id          BIGINT UNSIGNED  NOT NULL,
    pipeline_id        BIGINT UNSIGNED  NOT NULL,
    user_id            BIGINT UNSIGNED  NOT NULL,
    job_id             BIGINT UNSIGNED  NULL,
    definition_version INT UNSIGNED     NOT NULL,
    definition         JSON             NOT NULL,
    status             ENUM('queued','running','succeeded','failed','cancelled','timed_out') NOT NULL DEFAULT 'queued',
    steps              JSON             NULL,
    output_dataset_id  BIGINT UNSIGNED  NULL,
    error_code         VARCHAR(40)      NULL,
    error_message      VARCHAR(300)     NULL,
    created_at         DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    started_at         DATETIME(3)      NULL,
    finished_at        DATETIME(3)      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pipeline_runs_public_id (public_id),
    UNIQUE KEY uq_pipeline_runs_tenant_id (tenant_id, id),
    KEY ix_pipeline_runs_pipeline (tenant_id, pipeline_id, id),
    CONSTRAINT ck_pipeline_runs_definition CHECK (json_valid(definition)),
    CONSTRAINT ck_pipeline_runs_steps CHECK (steps IS NULL OR json_valid(steps)),
    CONSTRAINT fk_pipeline_runs_pipeline FOREIGN KEY (tenant_id, pipeline_id) REFERENCES pipelines (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pipeline_runs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provenance edges between datasets of one workspace (derived server-side, never from client input).
CREATE TABLE dataset_lineage (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    workspace_id      BIGINT UNSIGNED NOT NULL,
    target_dataset_id BIGINT UNSIGNED NOT NULL,
    source_dataset_id BIGINT UNSIGNED NOT NULL,
    via               ENUM('ingest','transform','pipeline') NOT NULL,
    pipeline_id       BIGINT UNSIGNED NULL,
    created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_dataset_lineage_edge (target_dataset_id, source_dataset_id, via),
    KEY ix_dataset_lineage_source (tenant_id, source_dataset_id),
    KEY ix_dataset_lineage_target (tenant_id, target_dataset_id),
    CONSTRAINT fk_dataset_lineage_target FOREIGN KEY (tenant_id, target_dataset_id) REFERENCES datasets (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_dataset_lineage_source FOREIGN KEY (tenant_id, source_dataset_id) REFERENCES datasets (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_dataset_lineage_workspace FOREIGN KEY (tenant_id, workspace_id) REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Object storage: containers inside a storage resource, objects inside containers. Object keys are display names;
-- the bytes live under STORAGE_PATH with generated keys (storage_key). lifecycle: {"archive_after_days": n, "delete_after_days": n}.
CREATE TABLE storage_containers (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id    CHAR(26)        NOT NULL,
    tenant_id    BIGINT UNSIGNED NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    resource_id  BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(63)     NOT NULL,
    lifecycle    JSON            NULL,
    created_by   BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_storage_containers_public_id (public_id),
    UNIQUE KEY uq_storage_containers_tenant_id (tenant_id, id),
    UNIQUE KEY uq_storage_containers_name (resource_id, name),
    CONSTRAINT ck_storage_containers_name CHECK (name REGEXP '^[a-z0-9][a-z0-9-]{2,62}$'),
    CONSTRAINT ck_storage_containers_lifecycle CHECK (lifecycle IS NULL OR json_valid(lifecycle)),
    CONSTRAINT fk_storage_containers_resource FOREIGN KEY (tenant_id, resource_id) REFERENCES resources (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_storage_containers_workspace FOREIGN KEY (tenant_id, workspace_id) REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_storage_containers_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage_objects (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id    CHAR(26)        NOT NULL,
    tenant_id    BIGINT UNSIGNED NOT NULL,
    container_id BIGINT UNSIGNED NOT NULL,
    object_key   VARCHAR(255)    NOT NULL,
    storage_key  CHAR(26)        NOT NULL,
    bytes        BIGINT UNSIGNED NOT NULL,
    content_type VARCHAR(100)    NOT NULL,
    sha256       BINARY(32)      NOT NULL,
    metadata     JSON            NULL,
    tier         ENUM('hot','cool','archive') NOT NULL DEFAULT 'hot',
    uploaded_by  BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_storage_objects_public_id (public_id),
    UNIQUE KEY uq_storage_objects_key (container_id, object_key),
    KEY ix_storage_objects_tenant (tenant_id, container_id),
    KEY ix_storage_objects_uploader (tenant_id, uploaded_by),
    CONSTRAINT ck_storage_objects_metadata CHECK (metadata IS NULL OR json_valid(metadata)),
    CONSTRAINT fk_storage_objects_container FOREIGN KEY (tenant_id, container_id) REFERENCES storage_containers (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_storage_objects_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
