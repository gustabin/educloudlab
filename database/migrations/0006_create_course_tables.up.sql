-- Minimal course management (M10a): courses, staff/student enrollments, lab assignments.

CREATE TABLE courses (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id      CHAR(26)        NOT NULL,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    owner_user_id  BIGINT UNSIGNED NOT NULL,
    code           VARCHAR(30)     NOT NULL,
    title          VARCHAR(150)    NOT NULL,
    slug           VARCHAR(100)    NOT NULL,
    description    TEXT            NULL,
    visibility     ENUM('private','public') NOT NULL DEFAULT 'private',
    status         ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    join_code_hash BINARY(32)      NULL,
    join_enabled   TINYINT(1)      NOT NULL DEFAULT 0,
    created_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_courses_public_id (public_id),
    UNIQUE KEY uq_courses_tenant_id (tenant_id, id),
    UNIQUE KEY uq_courses_tenant_code (tenant_id, code),
    UNIQUE KEY uq_courses_slug (slug),
    UNIQUE KEY uq_courses_join_code (join_code_hash),
    KEY ix_courses_public (visibility, status),
    CONSTRAINT fk_courses_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_courses_owner  FOREIGN KEY (owner_user_id) REFERENCES users (id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enrollments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    course_id   BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    role        ENUM('student','instructor') NOT NULL DEFAULT 'student',
    status      ENUM('active','dropped','completed') NOT NULL DEFAULT 'active',
    enrolled_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrollments_course_user (course_id, user_id),
    KEY ix_enrollments_user (tenant_id, user_id, status),
    CONSTRAINT fk_enrollments_course FOREIGN KEY (tenant_id, course_id)
        REFERENCES courses (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_labs (
    id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id  BIGINT UNSIGNED  NOT NULL,
    course_id  BIGINT UNSIGNED  NOT NULL,
    lab_id     BIGINT UNSIGNED  NOT NULL,
    position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_required TINYINT(1)      NOT NULL DEFAULT 1,
    due_at     DATETIME(3)      NULL,
    created_at DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_course_labs (course_id, lab_id),
    KEY ix_course_labs_tenant (tenant_id, course_id, position),
    CONSTRAINT fk_course_labs_course FOREIGN KEY (tenant_id, course_id)
        REFERENCES courses (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_course_labs_lab FOREIGN KEY (lab_id) REFERENCES labs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
