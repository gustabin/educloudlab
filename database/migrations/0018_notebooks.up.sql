-- M8 (release 1.3, ADR-010): notebooks (resource type 'notebook') and their runs in the Docker sandbox.

ALTER TABLE jobs
    MODIFY type ENUM('ingest','sql_query','transform','validate','pipeline_run','cleanup','profile','semantic_query','notebook_run') NOT NULL;

-- cells: [{"id", "type": "markdown"|"code", "source"}]
CREATE TABLE notebooks (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED  NOT NULL,
    workspace_id  BIGINT UNSIGNED  NOT NULL,
    resource_id   BIGINT UNSIGNED  NOT NULL,
    cells         JSON             NOT NULL,
    version       INT UNSIGNED     NOT NULL DEFAULT 1,
    created_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_notebooks_resource (resource_id),
    UNIQUE KEY uq_notebooks_tenant_id (tenant_id, id),
    KEY ix_notebooks_workspace (tenant_id, workspace_id),
    CONSTRAINT ck_notebooks_cells CHECK (json_valid(cells)),
    CONSTRAINT fk_notebooks_resource FOREIGN KEY (tenant_id, resource_id) REFERENCES resources (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_notebooks_workspace FOREIGN KEY (tenant_id, workspace_id) REFERENCES workspaces (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cells: snapshot executed; outputs/artifacts: normalised, size-capped results (student-controlled data).
CREATE TABLE notebook_runs (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id         CHAR(26)        NOT NULL,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    notebook_id       BIGINT UNSIGNED NOT NULL,
    user_id           BIGINT UNSIGNED NOT NULL,
    job_id            BIGINT UNSIGNED NULL,
    notebook_version  INT UNSIGNED    NOT NULL,
    cells             JSON            NOT NULL,
    status            ENUM('queued','running','succeeded','failed','cancelled','timed_out') NOT NULL DEFAULT 'queued',
    outputs           JSON            NULL,
    artifacts         JSON            NULL,
    error_code        VARCHAR(40)     NULL,
    error_message     VARCHAR(300)    NULL,
    duration_ms       INT UNSIGNED    NULL,
    created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at       DATETIME(3)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notebook_runs_public_id (public_id),
    UNIQUE KEY uq_notebook_runs_tenant_id (tenant_id, id),
    KEY ix_notebook_runs_notebook (tenant_id, notebook_id, id),
    CONSTRAINT ck_notebook_runs_cells CHECK (json_valid(cells)),
    CONSTRAINT ck_notebook_runs_outputs CHECK (outputs IS NULL OR json_valid(outputs)),
    CONSTRAINT ck_notebook_runs_artifacts CHECK (artifacts IS NULL OR json_valid(artifacts)),
    CONSTRAINT fk_notebook_runs_notebook FOREIGN KEY (tenant_id, notebook_id) REFERENCES notebooks (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_notebook_runs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
