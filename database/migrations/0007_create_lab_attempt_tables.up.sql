-- Lab Engine runtime state (M6): attempts, per-task results per submission, hint usage.
-- Composite FKs cannot use SET NULL (tenant_id is NOT NULL): cleanup code must clear
-- lab_attempts.workspace_id before hard-deleting a workspace; courses are archived, not deleted.

CREATE TABLE lab_attempts (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id         CHAR(26)        NOT NULL,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    user_id           BIGINT UNSIGNED NOT NULL,
    lab_id            BIGINT UNSIGNED NOT NULL,
    course_id         BIGINT UNSIGNED NULL,
    workspace_id      BIGINT UNSIGNED NULL,
    status            ENUM('in_progress','validating','completed','abandoned','expired') NOT NULL DEFAULT 'in_progress',
    score             DECIMAL(6,2)    NOT NULL DEFAULT 0,
    best_score        DECIMAL(6,2)    NOT NULL DEFAULT 0,
    max_score         DECIMAL(6,2)    NOT NULL,
    hints_used        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    submissions       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    started_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_submitted_at DATETIME(3)     NULL,
    completed_at      DATETIME(3)     NULL,
    updated_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_lab_attempts_public_id (public_id),
    UNIQUE KEY uq_lab_attempts_tenant_id (tenant_id, id),
    KEY ix_lab_attempts_user_lab (tenant_id, user_id, lab_id, status),
    KEY ix_lab_attempts_course (tenant_id, course_id, lab_id),
    CONSTRAINT fk_lab_attempts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_lab_attempts_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE,
    CONSTRAINT fk_lab_attempts_lab    FOREIGN KEY (lab_id)    REFERENCES labs (id)    ON DELETE RESTRICT,
    CONSTRAINT fk_lab_attempts_course FOREIGN KEY (tenant_id, course_id)
        REFERENCES courses (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_lab_attempts_workspace FOREIGN KEY (tenant_id, workspace_id)
        REFERENCES workspaces (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lab_task_results (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED  NOT NULL,
    attempt_id    BIGINT UNSIGNED  NOT NULL,
    submission_no SMALLINT UNSIGNED NOT NULL,
    task_key      VARCHAR(40)      NOT NULL,
    passed        TINYINT(1)       NOT NULL,
    points        DECIMAL(6,2)     NOT NULL DEFAULT 0,
    feedback      VARCHAR(500)     NULL,
    evidence      JSON             NULL,
    created_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_lab_task_results (attempt_id, submission_no, task_key),
    KEY ix_lab_task_results_tenant (tenant_id, attempt_id),
    CONSTRAINT ck_lab_task_results_evidence CHECK (evidence IS NULL OR json_valid(evidence)),
    CONSTRAINT fk_lab_task_results_attempt FOREIGN KEY (tenant_id, attempt_id)
        REFERENCES lab_attempts (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Primary key guarantees each hint penalty is applied at most once per attempt.
CREATE TABLE lab_hint_usage (
    tenant_id  BIGINT UNSIGNED  NOT NULL,
    attempt_id BIGINT UNSIGNED  NOT NULL,
    task_key   VARCHAR(40)      NOT NULL,
    hint_index TINYINT UNSIGNED NOT NULL,
    penalty    DECIMAL(6,2)     NOT NULL DEFAULT 0,
    used_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (attempt_id, task_key, hint_index),
    KEY ix_lab_hint_usage_tenant (tenant_id, attempt_id),
    CONSTRAINT fk_lab_hint_usage_attempt FOREIGN KEY (tenant_id, attempt_id)
        REFERENCES lab_attempts (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
