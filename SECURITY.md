# Security policy

EduCloud Lab executes student SQL, pipelines and (optionally) Python notebooks, so security reports are very welcome.

## Reporting a vulnerability

**Please do not open a public issue.** Report it privately through GitHub: **Security → Report a vulnerability** on this repository (private vulnerability reporting).

Include:
- the affected version (see `VERSION`) and component;
- steps to reproduce, or a proof of concept;
- the impact you observed.

You should get an acknowledgement within 7 days. Fixes are released as a new version and credited, unless you prefer otherwise.

## Supported versions

| Version | Supported |
|---|---|
| 1.3.x | Yes |
| < 1.3 | No: upgrade to the latest release |

## Scope and design

The security model is documented in:
- `docs/IMPLEMENTATION_MASTER_PLAN.md` §14 (threat model);
- `docs/architecture/` (execution plane, SQL sandbox, notebooks in Docker, observability);
- `docs/deployment/DEPLOYMENT.md` (production hardening).

Every milestone passed a security gate; the gate checklist is `.claude/skills/educloud-security-gate/SKILL.md`.

Especially in scope:
- tenant isolation (cross-tenant access must return 404);
- sandbox escapes from the SQL Lab, pipelines or notebooks;
- authentication, CSRF and JWT;
- upload handling;
- information disclosure in API responses or logs.

Out of scope:
- the development stack (XAMPP, PHP 8.1, MariaDB 10.4), which is end of life and documented as never to be exposed to the Internet;
- findings that need a compromised server or administrator account;
- denial of service through traffic volume alone.
