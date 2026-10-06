---
name: educloud-security-gate
description: EduCloud Lab milestone security gate checklist and security test commands (tenant isolation, auth/JWT, CSRF, XSS, SQLi, uploads, SQL sandbox, secrets). Use before closing any milestone or security-sensitive task. Complements the built-in /security-review.
---

# Security Gate

## When
At the end of every milestone, and on any task touching auth, tenancy, uploads, execution, SQL or output rendering.

## Automated checks (all must pass, paste output verbatim)
```
composer check
vendor\bin\phpunit --testsuite Security
worker\.venv\Scripts\python -m pytest worker/tests -q
composer audit
```

## Manual checklist
- [ ] **Tenant isolation:** every new `{id}` route is in the route registry. `TenantIsolationTest` covers it and returns 404 cross-tenant.
- [ ] **AuthZ:** permission is declared on the route. Role tests cover 403.
- [ ] **Input:** server-side validation rejects unknown fields. Identifiers come from allowlists.
- [ ] **SQL:** only prepared statements (`grep -rn "query(\"" app/` shows nothing with variables).
- [ ] **Output:** `e()` in views. No `.html(` with API data in `public/assets/js`. SweetAlert2: `titleText`/`text` only; `showValidationMessage(` and `title:` must never receive unescaped server text (`git grep -n "showValidationMessage(\|title:" public/assets/js`).
- [ ] **CSRF:** state-changing session routes are protected. There is a test without a token.
- [ ] **Auth/JWT:** pinned alg, exp/iss/aud checked, refresh rotation and reuse detection tested.
- [ ] **Uploads:** generated keys, realpath guard, size/type/content checks, stored outside `public/`.
- [ ] **Execution:** no student code in Apache. The runner has no credentials. Sandbox settings are locked. Timeouts and caps are tested.
- [ ] **Secrets:** nothing secret in git (`git grep -nIE "(password|secret|api_key)\s*=" -- ':!*.example'`). Logs are redacted.
- [ ] **Errors:** no stack traces, SQL or paths in responses. A request_id is present.
- [ ] **Headers:** CSP, nosniff, Referrer-Policy and `X-Robots-Tag` on private pages.

## Output
A findings table (severity, file:line, issue, fix, status). A milestone is blocked while any Critical or High finding is open.
