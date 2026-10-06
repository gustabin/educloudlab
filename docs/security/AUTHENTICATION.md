# Authentication & Authorization (M2)

Implements ADR-003 (sessions for the browser, JWT for API clients) and ADR-007 (fixed roles).

## Request pipeline

```
Router → SecurityHeaders (global)
       → RateLimit        route option 'rate'       (IP policies, config/security.php)
       → Authenticate     route option 'auth'       none | session | jwt | any
       → CsrfProtection   unsafe methods unless Bearer or 'csrf' => false
       → ResolveTenant    when auth != none          (membership re-validated every request)
       → Authorize        route option 'permission'  (config/permissions.php)
       → handler
```

## Browser sessions

| Aspect | Implementation |
|---|---|
| **Cookie** | `ecsid`: 256-bit random value; `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS. Path is the app base path. Session cookie with no persistent expiry. |
| **Storage** | Table `sessions`. Only `SHA-256(id)` is stored, so a DB dump does not yield usable cookies. |
| **Lifetime** | 30 min idle, 8 h absolute (`config/security.php → session`). Expired rows are deleted on sight and the cookie is cleared. |
| **Fixation** | A new id is always created at login; client-supplied ids are never adopted. |
| **Revocation** | Logout deletes the row. A password reset deletes all of the user's sessions and refresh tokens. A disabled user or suspended membership loses access on the next request. |

## CSRF

Tokens are stateless HMACs keyed with `APP_HASH_KEY` (`app/Core/Csrf.php`):

- **Logged in:** `HMAC("sess|" + session id)`. Rotates on every login.
- **Anonymous** (login, register and reset forms): `HMAC("anon|" + ec_csrf cookie)`. `ec_csrf` is a random `HttpOnly` cookie set on HTML pages only.

Rules:
- Pages expose the token in `<meta name="csrf-token">`, and `api.js` sends it as `X-CSRF-Token`.
- A foreign `Origin` header is rejected even when the token is valid.
- Exempt requests:
  - those with a verified Bearer token, since they carry no cookies;
  - credential-exchange routes declared `'csrf' => false` (`/auth/tokens*`), which never read cookies.
- `CsrfTest::testEveryUnsafeBrowserRouteEnforcesCsrf` checks every unsafe route in the registry automatically.

## Passwords and accounts

- **Hashing:** Argon2id (`PASSWORD_ARGON2ID`), with transparent rehash on login.
- **Password policy:** 12–128 characters, no composition rules. Blocks common passwords, the email address or its local part, and passwords made of one or two repeated characters.
- **Registration:** creates a `pending` user plus a personal tenant, with the user as `org_admin`.
  - **Activation:** an email verification link (24 h) **together with the account password** activates the account. A wrong password does not consume the token.
  - **Re-registration while pending:** a new registration for a still-`pending` address replaces its credentials and invalidates older links. Together with the password requirement, this prevents pre-registration account takeover (M2 gate finding).
  - **Unverified login:** returns `EMAIL_NOT_VERIFIED`, but only after a correct password.
  - **Email content:** emails sent before verification contain no user-supplied text.
- **Anti-enumeration:**
  - register, resend and forgot always answer 202 with the same message;
  - a second registration for an existing address emails the owner instead;
  - login failures share one message (`INVALID_CREDENTIALS`) and the same timing (a dummy Argon2id verify runs for unknown accounts).
- **Lockout:** a soft lock of 15 min after 10 consecutive failures, deliberately non-escalating. The owner is emailed (`account_locked`) with a reset link, and a password reset clears the lock. The counter resets on success.
- **Rate limits:** see `config/security.php → rate_limits`.
  - `auth_ip`: 30 per 15 min, all auth endpoints
  - `login_account`: 10 per 15 min, per (email, client IP), so an attacker elsewhere cannot exhaust the owner's allowance
  - `register_ip`: 10 per hour
  - `email_account`: 3 emails per hour, per address
  - `write_user`: 60 unsafe (POST/PATCH/PUT/DELETE) requests per minute, per authenticated user
  - Keys are HMACs; no raw IPs or emails are stored.
- **One-time tokens** (verify and reset): 256-bit, stored hashed, single use (atomic `used_at` update). A newer token invalidates older ones. A reset token is not consumed when the new password is rejected.

## API clients (JWT)

| Aspect | Implementation |
|---|---|
| **Issue** | `POST /api/v1/auth/tokens` with email and password returns an access token (HS256, 15 min) plus a refresh token (opaque, 30 days, stored hashed). |
| **Claims** | `iss`, `aud`, `sub` (user ULID), `tid` (tenant ULID), `iat`, `nbf`, `exp`, `jti`. Header `kid`. |
| **Verification** | The algorithm is pinned per key, and an unknown `kid` is rejected. `iss`/`aud` are checked. A 30 s leeway applies. The user must be active, and the membership is re-checked on every request. |
| **Key rotation** | `JWT_KEYS=k2:new,k1:old`. The first key signs and all keys verify. Remove the old key after 15 min. |
| **Refresh** | Each refresh token is single use and is rotated. Reusing a rotated or revoked token revokes the whole family and is audited (`auth.token_refresh` denied). |
| **Transport** | Only via `Authorization: Bearer`. Under Apache + mod_php the header is read from `apache_request_headers()`, because it is not exposed in `$_SERVER`. |

## Authorization (RBAC)

The roles `org_admin`, `instructor`, `student` and `read_only` live in `memberships.role`. Their permission map is in `config/permissions.php`. `users.is_platform_admin` grants every permission.

Routes declare a `permission`, and object-level ownership and course scope are enforced in services (from M3). Cross-tenant access returns 404.

## Email

- **Queueing:** auth emails go into `email_outbox` and are delivered by `php scripts/mailer.php` (`--once` for a single pass).
- **Drivers:**
  - `MAIL_DRIVER=file` writes `.eml` files to `{STORAGE_PATH}/mail`, for development.
  - `smtp` uses PHPMailer with STARTTLS or SMTPS.
- **Payload handling:** the payload, which contains the one-time link, is cleared after sending. SMTP credentials and payloads are never logged.

## Audit events

`auth.register`, `auth.verify_email`, `auth.verify_email_resend`, `auth.login`, `auth.logout`, `auth.password_forgot`, `auth.password_reset`, `auth.token_issue`, `auth.token_refresh`, `auth.token_revoke`. Each records an outcome and an HMAC of the IP; passwords and tokens are never recorded.

## Accepted residual risks (M2 security gate, 2026-10-06)

| Risk | Mitigation today | Planned |
|---|---|---|
| A distributed attacker can trigger the 15-minute soft lock on a known email | Short, non-escalating lock; owner notified; reset unlocks | CAPTCHA or progressive delay (M11) |
| Access JWTs stay valid up to 15 min after a password reset or a refresh revocation | Short TTL; user status re-checked per request | `password_changed_at` / token version check (M11) |
| Small timing differences between existing and new accounts (not measured) | Argon2id hash and dummy verify on all paths; rate limits | Measure; move side effects to async (M11) |
| `Secure` cookie flag and HSTS depend on `$_SERVER['HTTPS']` (TLS-terminating proxy) | Localhost-only dev install | Trusted-proxy / force-secure setting (M12, deployment) |
| Pending accounts are never purged | Credentials are replaced on re-registration | Scheduler cleanup after the token TTL (M11) |
