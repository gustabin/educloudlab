# EduCloud Lab — Project Instructions

EduCloud Lab is an independent educational platform for practising cloud and data-platform concepts: workspaces, resources, storage, a medallion lakehouse, SQL, pipelines and auto-graded labs. It is **not** affiliated with Microsoft. Never use Microsoft branding, logos or copied UI.

Master plan: `docs/IMPLEMENTATION_MASTER_PLAN.md`. Decisions: `docs/adr/`. Read both before architectural changes.

## Stack (mandatory)
- **PHP 8.1.6** (XAMPP; ADR-001). Use PHP 8.1 syntax only: no readonly classes, DNF types, typed class constants or `json_validate()`.
- MariaDB 10.4 in dev, MySQL 8 target, accessed through **MySQLi with prepared statements**. SQL must stay portable (ADR-002).
- Server-rendered PHP views + jQuery/AJAX + Bootstrap 5.3 + SweetAlert2 + Font Awesome, all vendored in `public/assets/vendor` (no CDN).
- Composer libraries: firebase/php-jwt, phpmailer/phpmailer, vlucas/phpdotenv, opis/json-schema, league/commonmark. Dev: PHPUnit 10.5, PHPStan, PHPCS.
- Execution plane: Python 3.11 + DuckDB runner in `worker/`, launched only by `scripts/dispatcher.php` (ADR-005).
- **Do not introduce** React, Vue, Angular, Laravel, Symfony framework, PostgreSQL, FastAPI or new dependencies without an approved ADR.

## Architecture rules
- Served at `http://localhost/educloudlab/` (ADR-013). The root `.htaccess` rewrites everything into `public/`, so nothing outside `public/` is reachable. Never hardcode root-relative links: use `url()` / `asset()` in PHP and `EduCloud.api.url()` in JS.
- Request flow: Router → SecurityHeaders → RateLimit → Authenticate → ResolveTenant → Authorize → Validate → Service → Repository → Response.
- Modules live in `app/Modules/<Name>/` (routes.php, Controller, Service, Repository, Validator, views/). Controllers are thin. SQL lives only in repositories.
- Tenant-owned repository methods take `TenantContext` first and always filter by `tenant_id`. The tenant is never taken from client input. Cross-tenant access returns **404**.
- URLs use `public_id` (ULID). Internal BIGINT ids never leave the server.
- PHP never executes student code or student SQL. It enqueues a job (`jobs` table) and returns 202.

## Security rules (non-negotiable)
- Prepared statements for every value. Dynamic identifiers (ORDER BY, table names) come from allowlists only.
- Escape all output with `e()`. In JS use `.text()`, never `.html()` with data.
- CSRF token on every state-changing browser request. Sessions are regenerated on login.
- Never log or return passwords, tokens, secrets, SQL errors, stack traces or filesystem paths.
- Uploads go to `STORAGE_PATH` under generated keys. User-supplied names are display-only.
- Secrets live only in `.env` (never committed). Runtime DB user is `educloud_app`, never root.

## API conventions
- `/api/v1`, JSON envelope `{success, data, message, meta}` or `{success:false, error:{code, message, details}}`.
- Status codes: 201 create, 202 job enqueued, 204 delete, 422 validation, 401/403/404/409/429.
- Update `docs/api/openapi.yaml` with every endpoint change.

## Database conventions
- Migrations go in `database/migrations/NNNN_name.up.sql` + `.down.sql`. Never edit an applied migration.
- InnoDB, utf8mb4_unicode_ci, snake_case plural tables, `created_at`/`updated_at` DATETIME(3) UTC.
- Composite FK `(tenant_id, parent_id)` on tenant-owned children.

## Commands
- `composer test` (PHPUnit), `composer stan`, `composer lint`, `composer check` (all three)
- `php scripts/check-env.php`, `php scripts/migrate.php up|down|status`
- `worker\.venv\Scripts\python -m pytest worker/tests`

## Definition of Done
Done means all of the following:
- Validation, authorization and error handling are implemented.
- Negative and security tests exist and pass, not only the happy path.
- The OpenAPI spec and docs are updated.
- UX loading, empty and error states are handled.
- The task's acceptance criteria are verified.

Report test output faithfully. Never mark untested work as done.

## Skills
`design`, `backend-feature`, `db-migration`, `data-execution`, `lab-authoring`, `educloud-security-gate`. Agents: `security-reviewer`, `ux-reviewer` (read-only reviewers; the main session owns edits).
