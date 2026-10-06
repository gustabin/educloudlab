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
