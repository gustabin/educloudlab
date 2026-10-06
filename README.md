# EduCloud Lab

An independent educational platform for practising cloud computing, data engineering, analytics and AI concepts: workspaces, resources, object storage, a medallion lakehouse (raw → bronze → silver → gold), SQL analytics, pipelines and auto-graded labs. It runs locally on XAMPP and needs no paid cloud subscription.

> EduCloud Lab is **not** affiliated with, endorsed by or certified by Microsoft. Azure and Fabric are mentioned only as conceptual references in educational material.

## Status
**M0 (Discovery & Architecture) complete.** The application itself is not implemented yet. See `docs/IMPLEMENTATION_MASTER_PLAN.md` for the roadmap.

## Stack
PHP 8.1 (XAMPP, see ADR-001) · MariaDB/MySQL via MySQLi · REST API + jQuery/AJAX · Bootstrap 5 · SweetAlert2 · PHPMailer · JWT for API clients · Python + DuckDB execution runner.

## Quick start (development)
```
composer install
copy .env.example .env      # then edit it
php scripts/check-env.php
```
Full setup (database users, vhost, storage, Python runner) will be in `docs/development/SETUP.md` (M1).

## Documentation
- `docs/IMPLEMENTATION_MASTER_PLAN.md`: architecture, scope, milestones, risks
- `docs/adr/`: architecture decision records
- `CLAUDE.md`: conventions for Claude Code sessions
- `THIRD_PARTY_NOTICES.md`: dependency licenses

## License
Proprietary, all rights reserved (ADR-012). See `LICENSE`.
