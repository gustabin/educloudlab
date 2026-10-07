-- M10b (release 1.2): course modules and lessons (CommonMark), lesson progress and in-app notifications.

CREATE TABLE course_modules (
    id         BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    public_id  CHAR(26)          NOT NULL,
    tenant_id  BIGINT UNSIGNED   NOT NULL,
    course_id  BIGINT UNSIGNED   NOT NULL,
    title      VARCHAR(150)      NOT NULL,
    summary    VARCHAR(500)      NULL,
    position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status     ENUM('draft','published') NOT NULL DEFAULT 'draft',
    created_at DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_course_modules_public_id (public_id),
    UNIQUE KEY uq_course_modules_tenant_id (tenant_id, id),
    KEY ix_course_modules_course (tenant_id, course_id, position),
    CONSTRAINT fk_course_modules_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- body_md: CommonMark source (rendered server-side with raw HTML escaped). lab_id: optional lab assigned to the course.
CREATE TABLE course_lessons (
    id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    public_id         CHAR(26)          NOT NULL,
    tenant_id         BIGINT UNSIGNED   NOT NULL,
    course_id         BIGINT UNSIGNED   NOT NULL,
    module_id         BIGINT UNSIGNED   NOT NULL,
    title             VARCHAR(150)      NOT NULL,
    body_md           MEDIUMTEXT        NOT NULL,
    estimated_minutes SMALLINT UNSIGNED NULL,
    due_at            DATETIME(3)       NULL,
    lab_id            BIGINT UNSIGNED   NULL,
    position          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status            ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_at      DATETIME(3)       NULL,
    created_at        DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at        DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_course_lessons_public_id (public_id),
    UNIQUE KEY uq_course_lessons_tenant_id (tenant_id, id),
    KEY ix_course_lessons_module (tenant_id, module_id, position),
    KEY ix_course_lessons_due (status, due_at),
    CONSTRAINT fk_course_lessons_module FOREIGN KEY (tenant_id, module_id) REFERENCES course_modules (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_course_lessons_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_course_lessons_lab FOREIGN KEY (lab_id) REFERENCES labs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lesson_progress (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    BIGINT UNSIGNED NOT NULL,
    lesson_id    BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_lesson_progress (lesson_id, user_id),
    KEY ix_lesson_progress_user (tenant_id, user_id),
    CONSTRAINT fk_lesson_progress_lesson FOREIGN KEY (tenant_id, lesson_id) REFERENCES course_lessons (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_progress_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app notifications. ref_key deduplicates one event per user (e.g. a lesson public id); link: server-generated app path.
CREATE TABLE notifications (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id  CHAR(26)        NOT NULL,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    kind       ENUM('lesson_published','lesson_due','lab_due') NOT NULL,
    ref_key    VARCHAR(60)     NOT NULL,
    title      VARCHAR(200)    NOT NULL,
    body       VARCHAR(300)    NULL,
    link       VARCHAR(255)    NULL,
    read_at    DATETIME(3)     NULL,
    created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_public_id (public_id),
    UNIQUE KEY uq_notifications_event (user_id, kind, ref_key),
    KEY ix_notifications_user (tenant_id, user_id, read_at, created_at),
    CONSTRAINT fk_notifications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
