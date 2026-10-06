# ADR-003: Server-side sessions for the browser, JWT only for API clients

- Status: Accepted

## Context
The spec requires JWT "for appropriate API clients" and warns against long-lived tokens in localStorage.

## Options
1. JWT everywhere.
2. JWT in cookies plus CSRF tokens.
3. Sessions for the browser and JWT for non-browser clients.

## Decision
Option 3.
- **Browser:** PHP session cookie (HttpOnly, SameSite=Lax, Secure on HTTPS) plus a CSRF synchronizer token.
- **API clients:** `firebase/php-jwt`, HS256 with a `kid` key ring, because ext-sodium is not loaded so EdDSA is unavailable. Access tokens last 15 minutes. Refresh tokens are opaque, stored hashed, and rotated on use with reuse detection.
- JWT is accepted only from the `Authorization: Bearer` header. Cookies are never read on the JWT path.

## Consequences
There are two authentication paths. Middleware must never mix them.

## Implementation notes (M2, 2026-10-06)
- **Sessions in MariaDB** (`sessions` table), not PHP native sessions:
  - XAMPP stores native sessions in the shared `C:\xampp\tmp`;
  - "log out everywhere" on a password reset needs sessions enumerable per user.
  Only SHA-256 of the session id is stored.
- **CSRF tokens are stateless HMACs.** They are bound to the session id, or to an `HttpOnly` anonymous cookie for login/register forms, so anonymous visitors create no database rows.
- **Passwords use Argon2id** (available in this PHP build) instead of `PASSWORD_DEFAULT` (bcrypt), which avoids bcrypt's 72-byte truncation.
- **Under Apache + mod_php, `Authorization` is missing from `$_SERVER`.** `Request::fromGlobals()` falls back to `apache_request_headers()`.
- **Details:** `docs/security/AUTHENTICATION.md`.
