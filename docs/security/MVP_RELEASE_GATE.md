# MVP release gate (M11a, 2026-10-07)

**Verdict: PASS.** No Critical, High or Medium findings. Every Low finding is fixed.

## Evidence

| Check | Result |
|---|---|
| `composer check` (PHPUnit Unit/Integration/Labs/Security + PHPStan + PHPCS) | OK, 344+ tests |
| `pytest worker/tests` | 128 passed |
| `npm run e2e` (spec §51 flow in Chrome + axe-core on 16 screens) | passed |
| `composer audit` / `npm audit` | no advisories / 0 vulnerabilities |
| Restore drill (`scripts/restore.php --dry-run`) | DRY RUN OK |
| HTTP probes | `.env`, `composer.*`, `.git`, migrations, docs → 403; `scripts/`, `labs/`, `storage/`, `vendor/`, `e2e/` → 404 |
| Headers | CSP, nosniff, `X-Frame-Options: DENY` and `frame-ancestors 'none'`, Referrer-Policy, Permissions-Policy and COOP everywhere; `X-Robots-Tag: noindex` and `no-store` on non-public responses |
| Error disclosure | 25 malformed requests: clean 404/422, with no stack traces, SQL or paths |

Each milestone gate is recorded with its architecture document:
- M2: `docs/security/AUTHENTICATION.md`
- M4 and M5: `docs/architecture/EXECUTION.md`
- M6: `docs/architecture/LAB_ENGINE.md`
- M10a: `docs/architecture/COURSES.md`

## M11a findings (all Low, fixed)

| Finding | Fix |
|---|---|
| The admin PATCH response re-read the user with a "contains" email search and could show a different account | Re-read by exact id; regression test with `ana@` and `xana@` |
| The temporary MySQL credentials file could survive a failed backup or restore (`exit` skips `finally`) | Removed by a shutdown handler on every exit; tools are resolved before the file is created; verified with a simulated failure |
| A restore `--dry-run` could target the live database if `DB_TEST_NAME` equalled `DB_NAME` | Refused when they are equal or empty, or when `APP_ENV=production` |
| A real restore moved the live storage aside before validating the archive | The archive is validated first (unsafe paths including backslashes, file count); storage is moved back if extraction fails |
| The E2E global setup (which wipes rate limits and creates accounts) had no environment guard | It refuses to run unless `APP_ENV` is local, development or testing and the host is localhost |

## Accepted residual risks (carried into production planning)

- **PHP 8.1 and MariaDB 10.4 are end of life (R16).** Production must use supported versions (`docs/deployment/DEPLOYMENT.md`).
- **The Windows runner has no OS sandbox beyond the process memory cap and DuckDB's own sandbox (R17).** Notebooks (arbitrary Python) stay gated behind Docker isolation (M8).
- **Storage quotas are per tenant and user.** Ingest and transforms can exceed the quota by up to one operation; the 200 MB per-workspace lakehouse cap bounds it.
- **`pip-audit` has not been run yet.** Python dependencies are only DuckDB (hash-pinned) and pytest (dev). This is part of the production checklist.
