-- M6 Lab Engine: saved SQL answers per task (re-executed server-side at validation) and the last validation error.

CREATE TABLE lab_task_answers (
    tenant_id  BIGINT UNSIGNED NOT NULL,
    attempt_id BIGINT UNSIGNED NOT NULL,
    task_key   VARCHAR(40)     NOT NULL,
    answer_sql MEDIUMTEXT      NOT NULL,
    created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (attempt_id, task_key),
    KEY ix_lab_task_answers_tenant (tenant_id, attempt_id),
    CONSTRAINT fk_lab_task_answers_attempt FOREIGN KEY (tenant_id, attempt_id)
        REFERENCES lab_attempts (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE lab_attempts
    ADD COLUMN last_error_code    VARCHAR(40)  NULL AFTER submissions,
    ADD COLUMN last_error_message VARCHAR(300) NULL AFTER last_error_code;
