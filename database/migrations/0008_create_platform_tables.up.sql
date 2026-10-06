-- Platform services: email outbox, audit log, usage metering (M2/M3/M11a).

-- payload may hold a one-time link token while pending; the mailer sets payload = NULL after sending.
CREATE TABLE email_outbox (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED  NULL,
    to_email        VARCHAR(254)     NOT NULL,
    template        VARCHAR(60)      NOT NULL,
    locale          VARCHAR(10)      NOT NULL DEFAULT 'es',
    payload         JSON             NULL,
    status          ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(40)      NULL,
    send_after      DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    sent_at         DATETIME(3)      NULL,
    created_at      DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY ix_email_outbox_queue (status, send_after),
    CONSTRAINT ck_email_outbox_payload CHECK (payload IS NULL OR json_valid(payload)),
    CONSTRAINT fk_email_outbox_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only. Intentionally no foreign keys: audit records must survive deletion of their subjects.
CREATE TABLE audit_logs (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    request_id         CHAR(26)        NULL,
    tenant_id          BIGINT UNSIGNED NULL,
    actor_user_id      BIGINT UNSIGNED NULL,
    action             VARCHAR(60)     NOT NULL,
    resource_type      VARCHAR(40)     NULL,
    resource_public_id CHAR(26)        NULL,
    outcome            ENUM('success','failure','denied') NOT NULL,
    ip_hash            BINARY(32)      NULL,
    meta               JSON            NULL,
    PRIMARY KEY (id),
    KEY ix_audit_tenant_time (tenant_id, occurred_at),
    KEY ix_audit_actor_time (actor_user_id, occurred_at),
    KEY ix_audit_action_time (action, occurred_at),
    CONSTRAINT ck_audit_logs_meta CHECK (meta IS NULL OR json_valid(meta))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usage_counters (
    tenant_id  BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    metric     ENUM('storage_bytes','job_seconds','jobs_count','queries_count') NOT NULL,
    period     CHAR(7)         NOT NULL COMMENT 'YYYY-MM, or "total" for gauges',
    value      BIGINT          NOT NULL DEFAULT 0,
    updated_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (tenant_id, user_id, metric, period),
    KEY ix_usage_user (user_id, metric, period),
    CONSTRAINT fk_usage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_usage_user   FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
