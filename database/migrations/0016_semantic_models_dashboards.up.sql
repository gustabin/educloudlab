-- M9 (release 1.2): semantic models, dashboards and their queries (renders).

ALTER TABLE resources
    MODIFY type ENUM('storage','lakehouse','dataset','pipeline','notebook','dashboard','semantic_model') NOT NULL;
ALTER TABLE jobs
    MODIFY type ENUM('ingest','sql_query','transform','validate','pipeline_run','cleanup','profile','semantic_query') NOT NULL;

-- A semantic model is a resource of type 'semantic_model' (API id = resource public id).
CREATE TABLE semantic_models (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED  NOT NULL,
    workspace_id  BIGINT UNSIGNED  NOT NULL,
    resource_id   BIGINT UNSIGNED  NOT NULL,
    definition    JSON             NOT NULL,
    version       INT UNSIGNED     NOT NULL DEFAULT 1,
    created_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_semantic_models_resource (resource_id),
    UNIQUE KEY uq_semantic_models_tenant_id (tenant_id, id),
    KEY ix_semantic_models_workspace (tenant_id, workspace_id),
    CONSTRAINT ck_semantic_models_definition CHECK (json_valid(definition)),
    CONSTRAINT fk_semantic_models_resource FOREIGN KEY (tenant_id, resource_id) REFERENCES resources (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_semantic_models_workspace FOREIGN KEY (tenant_id, workspace_id) REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A dashboard is a resource of type 'dashboard' bound to one semantic model of the same workspace.
CREATE TABLE dashboards (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED  NOT NULL,
    workspace_id  BIGINT UNSIGNED  NOT NULL,
    resource_id   BIGINT UNSIGNED  NOT NULL,
    model_id      BIGINT UNSIGNED  NOT NULL,
    definition    JSON             NOT NULL,
    version       INT UNSIGNED     NOT NULL DEFAULT 1,
    created_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_dashboards_resource (resource_id),
    UNIQUE KEY uq_dashboards_tenant_id (tenant_id, id),
    KEY ix_dashboards_workspace (tenant_id, workspace_id),
    KEY ix_dashboards_model (tenant_id, model_id),
    CONSTRAINT ck_dashboards_definition CHECK (json_valid(definition)),
    CONSTRAINT fk_dashboards_resource FOREIGN KEY (tenant_id, resource_id) REFERENCES resources (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_dashboards_workspace FOREIGN KEY (tenant_id, workspace_id) REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_dashboards_model FOREIGN KEY (tenant_id, model_id) REFERENCES semantic_models (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per executed request: an exploration query on a model, or a dashboard render (all widgets in one job).
-- request: the validated request snapshot; the result lives in meta/results/{public_id}.json for 24 h.
CREATE TABLE semantic_queries (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id     CHAR(26)        NOT NULL,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    workspace_id  BIGINT UNSIGNED NOT NULL,
    model_id      BIGINT UNSIGNED NOT NULL,
    dashboard_id  BIGINT UNSIGNED NULL,
    user_id       BIGINT UNSIGNED NOT NULL,
    job_id        BIGINT UNSIGNED NULL COMMENT 'Soft link to jobs.id (no FK: jobs are purged independently)',
    kind          ENUM('explore','render') NOT NULL,
    request       JSON            NOT NULL,
    status        ENUM('queued','succeeded','failed','cancelled','timed_out') NOT NULL DEFAULT 'queued',
    error_code    VARCHAR(40)     NULL,
    error_message VARCHAR(300)    NULL,
    duration_ms   INT UNSIGNED    NULL,
    created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at   DATETIME(3)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_semantic_queries_public_id (public_id),
    KEY ix_semantic_queries_user (tenant_id, user_id, created_at),
    KEY ix_semantic_queries_model (tenant_id, model_id),
    CONSTRAINT ck_semantic_queries_request CHECK (json_valid(request)),
    CONSTRAINT fk_semantic_queries_workspace FOREIGN KEY (tenant_id, workspace_id) REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_semantic_queries_model FOREIGN KEY (tenant_id, model_id) REFERENCES semantic_models (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_semantic_queries_dashboard FOREIGN KEY (tenant_id, dashboard_id) REFERENCES dashboards (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_semantic_queries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
