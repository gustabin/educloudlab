# ADR-013: Serve the app from an htdocs sub-directory

- Status: Accepted (2026-10-06, user decision). Refines ADR-011 and plan §8 "Hosting".

## Context
The user wants to open the app at `http://localhost/EduCloud%20Lab/` without changing the shared Apache vhosts or the Windows hosts file.

## Options
1. A vhost `educloud.test` with DocumentRoot at `public/`.
2. The sub-directory URL, with internal rewriting into `public/`.

## Decision
Option 2.
- **Root `.htaccess`:** rewrites every request internally to `public/$1`. It fails closed (`Require all denied`) when `mod_rewrite` is missing, and a FilesMatch deny list covers sensitive files as defense in depth.
- **Base path:** comes from the path of `APP_URL` (`app.base_path`).
  - `Request::fromGlobals()` strips it from incoming paths.
  - The `url()`/`asset()` helpers and `api.js` (`<meta name="app-base">`) add it to outgoing links.
- **Cookies (M2):** must use the base path as their `path`.

## Consequences
- **Verified:** `.env`, `composer.*`, `vendor/`, `app/`, `config/`, `database/`, `storage/`, `scripts/`, `.git/` and traversal variants all return 403 or 404 through Apache.
- Templates must never hardcode root-relative links. Always use `url()`/`asset()`.
- Moving to a vhost or a domain root later only needs a new `APP_URL`, with no code changes.
- The PHP built-in server (`scripts/dev-router.php`) serves from `/`, so set `APP_URL=http://127.0.0.1:8099` when using it.
