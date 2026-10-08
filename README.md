# EduCloud Lab

[![ci](https://github.com/gustabin/educloudlab/actions/workflows/ci.yml/badge.svg)](https://github.com/gustabin/educloudlab/actions/workflows/ci.yml) [![License: Apache-2.0](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

An independent educational platform for practising cloud computing, data engineering, analytics and AI concepts: workspaces, resources, object storage, a medallion lakehouse (raw → bronze → silver → gold), SQL analytics, pipelines and auto-graded labs. It runs locally on XAMPP and needs no paid cloud subscription.

> EduCloud Lab is **not** affiliated with, endorsed by or certified by Microsoft. Azure and Fabric are mentioned only as conceptual references in educational material.

## Status
**Release 1.3.0:** every milestone of the master plan is done (M0–M12). See `CHANGELOG.md`.
- **1.0 (MVP):**
  - accounts, sessions, CSRF, RBAC, JWT;
  - organizations with tenant isolation tests on every route;
  - workspaces and resources, CSV → raw → bronze;
  - sandboxed SQL Lab with silver/gold transforms;
  - Lab Engine (LAB-001/003/004/005), minimal courses;
  - admin monitor, quotas, public catalog with SEO, backups.
- **1.1:**
  - declarative ETL pipelines with quality checks and lineage;
  - JSON/Parquet ingestion, object storage;
  - LAB-002 and LAB-006.
- **1.2:**
  - warehouse star schemas, semantic models, dashboards;
  - course modules and lessons, notifications;
  - LAB-007 and LAB-009.
- **1.3:**
  - Python notebooks in a Docker sandbox (LAB-008);
  - the end-to-end capstone LAB-010;
  - observability (admin health, metrics, log lookup, Prometheus `/metrics`);
  - Linux CI in Docker, release archives, Linux deployment files.

## Stack
PHP 8.1+ (XAMPP in development, see ADR-001; CI also runs PHP 8.3) · MariaDB/MySQL via MySQLi · REST API + jQuery/AJAX · Bootstrap 5 · SweetAlert2 · PHPMailer · JWT for API clients · Python + DuckDB execution runner.

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
- `docs/architecture/`: execution plane, Lab Engine, courses, notebooks, analytics, observability, performance
- `docs/deployment/`: production deployment (release archives, `deploy/` units), backup and restore
- `docs/development/CI.md`: CI in Docker (PHP 8.1/8.3 × MySQL 8 / MariaDB), releases, pinned versions
- `THIRD_PARTY_NOTICES.md`: dependency licenses

## License
[Apache License 2.0](LICENSE) (ADR-012). See `NOTICE` and `THIRD_PARTY_NOTICES.md`. Contributions: `CONTRIBUTING.md`. Security reports: `SECURITY.md`.
