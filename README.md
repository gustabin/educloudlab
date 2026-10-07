# EduCloud Lab

An independent educational platform for practising cloud computing, data engineering, analytics and AI concepts: workspaces, resources, object storage, a medallion lakehouse (raw → bronze → silver → gold), SQL analytics, pipelines and auto-graded labs. It runs locally on XAMPP and needs no paid cloud subscription.

> EduCloud Lab is **not** affiliated with, endorsed by or certified by Microsoft. Azure and Fabric are mentioned only as conceptual references in educational material.

## Status
**MVP (release 1.0) complete:** milestones M0–M6, M10a and M11a. The release gate passed (`docs/security/MVP_RELEASE_GATE.md`).
- Accounts and security:
  - Accounts, sessions, CSRF, JWT for API clients.
  - Organizations with roles, plus tenant isolation tests on every route.
- Data platform:
  - Workspaces and resources.
  - CSV upload → raw → bronze ingestion in a per-workspace DuckDB lakehouse.
  - Sandboxed SQL Lab with silver/gold transforms.
- Learning:
  - Lab Engine with auto-graded labs LAB-001/003/004/005.
  - Minimal courses: join codes, assignments, instructor progress grid.
- Operations:
  - Admin monitor, storage quotas and usage metering.
  - Public catalog with SEO.
  - Backup and restore, deployment guide.
  - End-to-end and accessibility tests (`npm run e2e`).
- **Next (release 1.1):** pipelines, JSON/Parquet ingestion, object-storage lab (M7). See `docs/IMPLEMENTATION_MASTER_PLAN.md`.

## Stack
PHP 8.1 (XAMPP, see ADR-001) · MariaDB/MySQL via MySQLi · REST API + jQuery/AJAX · Bootstrap 5 · SweetAlert2 · PHPMailer · JWT for API clients · Python + DuckDB execution runner.

## Quick start (development)
```
composer install
copy .env.example .env      # then edit it
php scripts/check-env.php
```
Open **http://localhost/educloudlab/** (Apache from XAMPP must be running).
Full setup (database users, migrations, vhost, assets): `docs/development/SETUP.md`.

## Documentation
- `docs/IMPLEMENTATION_MASTER_PLAN.md`: architecture, scope, milestones, risks
- `docs/adr/`: architecture decision records
- `CLAUDE.md`: conventions for Claude Code sessions
- `docs/architecture/`: execution plane, Lab Engine, courses, performance
- `docs/deployment/`: production deployment, backup and restore
- `THIRD_PARTY_NOTICES.md`: dependency licenses

## License
Proprietary, all rights reserved (ADR-012). See `LICENSE`.
