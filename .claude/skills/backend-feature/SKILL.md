---
name: backend-feature
description: How to add or change an EduCloud Lab PHP module, REST endpoint, service, repository (MySQLi) or authorization rule, including tests and the OpenAPI update. Use for any backend task under app/, config/ or public/index.php.
---

# Backend Feature (PHP module + REST)

## Purpose
Every backend change follows one modular pattern with security built in.

## When to use / not use
- Use for endpoints, services, repositories, middleware and permissions.
- For schema changes, also use `db-migration`. For runner/DuckDB work, use `data-execution`.

## Required inputs
The task ID (e.g. `M3-T02`), the endpoint(s), the permission, the tenant scope and the acceptance criteria.

## Workflow
1. Read the task in the master plan and the related ADRs. Inspect the existing module before adding files.
2. **Routes:** `app/Modules/<Name>/routes.php` declares method, path, handler, `auth` (`session|jwt|any|none`), `permission`, `rate` policy and `schema`.
3. **Validator:** declarative rules (type, required, length, enum, regex, ULID). Unknown fields are rejected. Errors go to 422 `VALIDATION_ERROR` with `details[] = {field, code, message}`.
4. **Service:** business rules, quotas, transactions (`$db->transaction(fn() => ...)`), and audit events (`Audit::record(...)`).
5. **Repository:** all SQL lives here, using `Db` prepared helpers. The first parameter is `TenantContext $ctx` for tenant data. Every query includes `tenant_id = ?`. ORDER BY comes from an allowlist map.
6. **Controller:** parse → validate → call service → `Response::json(...)`. No SQL and no business logic.
7. **OpenAPI:** update `docs/api/openapi.yaml` (request, responses, error codes, security).
8. **Tests:** see below. Run `composer check`.

## Standards
- PHP 8.1 syntax. `declare(strict_types=1);`. PSR-12. PSR-4 namespace `EduCloud\`.
- Use public ULIDs in URLs and responses. Never expose internal ids.
- Cross-tenant or missing returns 404 `NOT_FOUND`. Authenticated without permission on own-tenant data returns 403.
- Use domain exceptions (`ValidationException`, `NotFoundException`, `ForbiddenException`, `ConflictException`, `QuotaExceededException`). The ErrorHandler maps them. Never echo exception messages from mysqli.
- Long-running or student-supplied computation goes through `Jobs::enqueue()` and returns 202 + job id.

## Security requirements
CSRF for session auth on POST/PATCH/PUT/DELETE. Rate-limit policy on auth and expensive endpoints. No secrets in logs. Output escaping in views.

## Tests (minimum per endpoint)
- Happy path (status, envelope, DB state, audit row).
- 422 for invalid input.
- 401 unauthenticated.
- 403 wrong role.
- **404 cross-tenant** (also covered automatically by `TenantIsolationTest` through the route registry).
- CSRF missing → 403 (session routes).

## Expected outputs
Code + tests + OpenAPI diff + a short summary with the test output pasted verbatim.
