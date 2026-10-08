# Contributing to EduCloud Lab

Thanks for helping! EduCloud Lab is an educational platform, so clarity and safety matter more than features.

## Before you start

- **Setup:** `docs/development/SETUP.md` (XAMPP on Windows) or the Docker CI (`docs/development/CI.md`).
- **Architecture and conventions:** `CLAUDE.md` (short) and `docs/IMPLEMENTATION_MASTER_PLAN.md` (full). Decisions are recorded in `docs/adr/`.
- **Larger changes:** open an issue first. Changes to the stack or new dependencies need an ADR.

## Rules that reviews enforce

- **Syntax:** PHP 8.1 only (ADR-001): no readonly classes, DNF types, typed class constants or `json_validate()`.
- **SQL:** MySQLi prepared statements for every value. Dynamic identifiers come from allowlists, and SQL lives only in repositories.
- **Tenancy:** tenant-owned queries take a `TenantContext` and filter by `tenant_id`. Cross-tenant access returns 404.
- **Output:** escape with `e()` in PHP. In JavaScript use `.text()`, never `.html()` with data.
- **Execution:** PHP never executes student code: it enqueues a job and returns 202.
- **API:** every endpoint change updates `docs/api/openapi.yaml`; a test checks coverage.
- **Done means:** validation, authorization, error handling, negative and security tests, docs, and UX loading, empty and error states.

## Checks

```
composer check                         # PHPCS + PHPStan + PHPUnit
worker/.venv/Scripts/python -m pytest worker/tests
npm run e2e                            # Playwright + axe (local install)
bash ci/test.sh                        # Linux CI in Docker (PHP 8.3 + MySQL 8.0)
```

All of them must pass. GitHub Actions runs the matrix on every pull request.

## Commits and pull requests

- Use conventional commit messages (`feat(m12): …`, `fix: …`, `docs: …`), and keep pull requests focused.
- **Never commit secrets:** `.env` is ignored, so keep it that way.
- **Licensing:** by contributing you agree that your contribution is licensed under the [Apache License 2.0](LICENSE), the same as the project (inbound = outbound).

## Security issues

Report them privately, not in public issues. See `SECURITY.md`.
