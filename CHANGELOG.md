# Changelog

Versions follow the releases of the master plan (`docs/IMPLEMENTATION_MASTER_PLAN.md` §6). Release archives are built with `php scripts/release.php <version>` (see `docs/deployment/DEPLOYMENT.md`).

## 1.3.0

**Notebooks and capstone**
- Python notebooks in a Docker sandbox (M8, ADR-010):
  - no network, read-only, resource limits, a PID 1 guard;
  - an isolation suite as the release gate;
  - a dedicated notebook worker.
- LAB-008, Python notebook exploration (needs `NOTEBOOKS_MODE=docker`).
- LAB-010, the end-to-end retail analytics capstone.

**Observability (M11b)**
- Request metrics by route name, and component heartbeats.
- Admin health, metrics and a log lookup by request id: the "Observabilidad" tab.

**Operations (M12)**
- CI in Docker (PHP 8.1/8.3 × MySQL 8.0 / MariaDB 10.11 / 10.4) and a GitHub Actions workflow.
- Release archives with checksums, Linux deployment files (systemd, Apache, PHP-FPM).
- A Prometheus `/metrics` endpoint behind a token.

## 1.2.0

- Warehouse star schemas, semantic models and dashboards with Chart.js (M9); LAB-007 and LAB-009.
- Course modules and lessons (CommonMark), lesson progress and in-app notifications (M10b).

## 1.1.0

- Declarative ETL pipelines with quality checks, and dataset lineage (M7).
- JSON and Parquet ingestion; object storage with containers, metadata and lifecycle tiers.
- LAB-002 and LAB-006.

## 1.0.0 (MVP)

- **Foundation:** front controller, router, JSON API envelope, security headers and CSP, i18n (M1).
- **Authentication:** registration with email verification, sessions with CSRF, rate limits, RBAC, and JWT for API clients (M2).
- **Tenancy:** multi-tenancy with composite tenant keys, workspaces and a resource lifecycle, audit log (M3).
- **Data:** CSV ingestion to bronze through the queued Python/DuckDB runner (M4); a sandboxed SQL Lab and silver/gold transforms (M5).
- **Lab Engine:** declarative, auto-graded labs: LAB-001, 003, 004 and 005 (M6).
- **Courses:** join codes, lab assignments and a progress grid (M10a).
- **Hardening:** admin monitor, quotas, public site with SEO, backups and restore drill, Playwright E2E with axe (M11a).
