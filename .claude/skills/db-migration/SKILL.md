---
name: db-migration
description: Create or review EduCloud Lab MySQL/MariaDB schema migrations (tables, indexes, foreign keys, tenant scoping, rollback). Use whenever a task adds or changes database schema or seed data.
---

# Database Migration

## When to use / not use
- Use for any DDL, index or seed change.
- Do not use for query-only repository changes (use `backend-feature`).

## Workflow
1. Check the ERD and table list in master plan §10. Add the table only if the current milestone needs it (YAGNI).
2. Create `database/migrations/NNNN_<verb>_<object>.up.sql` and a matching `.down.sql`. Take the next number; never edit an applied migration.
3. Apply: `php scripts/migrate.php up`. Roll back: `php scripts/migrate.php down`, then `up` again. The round trip must succeed on a fresh DB.
4. Update `docs/database/SCHEMA.md` (purpose + owner per table) and the Mermaid ERD.
5. Back up before applying to any non-test DB: `C:\xampp\mysql\bin\mysqldump --single-transaction educloud > backup.sql`.

## Standards
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- PK `id BIGINT UNSIGNED AUTO_INCREMENT`. Exposed entities also get `public_id CHAR(26) NOT NULL UNIQUE` (ULID).
- Tenant-owned tables:
  - `tenant_id BIGINT UNSIGNED NOT NULL` as the leading column of the main indexes.
  - `UNIQUE (tenant_id, id)` on parents.
  - Children reference parents with **composite FKs** `(tenant_id, parent_id) REFERENCES parent(tenant_id, id)`.
- Timestamps: `created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)`, `updated_at ... ON UPDATE CURRENT_TIMESTAMP(3)`. Store UTC.
- Enums as `ENUM(...)` or `VARCHAR` + `CHECK`. JSON as `JSON` + `CHECK (json_valid(col))`. Use JSON only for genuinely schemaless config.
- Explicit FK `ON DELETE` behaviour (usually `RESTRICT`, or `CASCADE` for owned children).
- Portable across MariaDB 10.4 and MySQL 8.0: no `SKIP LOCKED`, no functional indexes, no engine-specific JSON functions.

## Security
- Migrations run as `educloud_migrator`. The app runs as `educloud_app` (DML only).
- No real personal data in seeds. Secrets never go in SQL files.

## Review criteria
- Up and down migrations both work on a fresh DB.
- Indexes support the repository queries.
- Tenant FKs are present.
- Docs and ERD are updated.
