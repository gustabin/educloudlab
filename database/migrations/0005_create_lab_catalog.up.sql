-- Global lab catalog imported from labs/LAB-xxx/lab.json by scripts/labs-import.php (M6).
-- Not tenant-owned: labs are platform content. Attempts are tenant-owned (0007).

CREATE TABLE labs (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id         CHAR(26)        NOT NULL,
    code              VARCHAR(20)     NOT NULL,
    version           VARCHAR(20)     NOT NULL,
    slug              VARCHAR(100)    NOT NULL,
    title             VARCHAR(150)    NOT NULL,
    summary           VARCHAR(500)    NOT NULL,
    difficulty        ENUM('beginner','intermediate','advanced') NOT NULL,
    estimated_minutes SMALLINT UNSIGNED NOT NULL,
    max_score         DECIMAL(6,2)    NOT NULL,
    definition        JSON            NOT NULL,
    checksum          BINARY(32)      NOT NULL,
    status            ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    is_current        TINYINT(1)      NOT NULL DEFAULT 0,
    created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_labs_public_id (public_id),
    UNIQUE KEY uq_labs_code_version (code, version),
    KEY ix_labs_slug (slug, is_current),
    KEY ix_labs_status (status, is_current),
    CONSTRAINT ck_labs_definition CHECK (json_valid(definition)),
    CONSTRAINT ck_labs_code CHECK (code REGEXP '^LAB-[0-9]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
