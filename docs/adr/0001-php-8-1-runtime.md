# ADR-001: Run on the installed PHP 8.1.6

- Status: Accepted (2026-10-06, user decision)

## Context
The master spec (§6) requires PHP 8.2+. Discovery found XAMPP with PHP 8.1.6. The same XAMPP also serves other projects in htdocs.

## Problem
Upgrading PHP risks breaking the other projects. Staying on 8.1 deviates from the spec, and PHP 8.1 has been end-of-life since 2025-12-31.

## Options
1. Install a second XAMPP 8.2 side by side.
2. Swap the PHP build inside the current XAMPP.
3. Upgrade the whole XAMPP in place.
4. Keep PHP 8.1.6.

## Decision
Option 4, keeping PHP 8.1.6, as decided by the user.

## Consequences
- Code uses PHP 8.1 syntax only: no readonly classes, DNF types, typed class constants or `json_validate()`.
- PHPUnit is capped at 10.5. Composer `config.platform.php` is set to 8.1.6 so dependencies resolve correctly.
- CI also runs PHP 8.3 to keep the upgrade path open.
- Risk R16: no security patches. The dev XAMPP must never be exposed publicly. PHP must be upgraded before any public deployment.
