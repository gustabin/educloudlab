# ADR-002: MariaDB 10.4 in development, portable SQL, MySQL 8 as the production target

- Status: Accepted

## Context
XAMPP ships MariaDB 10.4.24, while the spec says MySQL.

## Decision
- SQL is written to the common subset of MariaDB 10.4 and MySQL 8.0: InnoDB, utf8mb4, CHECK constraints, JSON columns as `JSON` (on MariaDB this is a LONGTEXT alias with `json_valid`).
- No `SKIP LOCKED`; a single dispatcher claims jobs instead.
- CI runs the integration tests on both engines.

## Consequences
The application never relies on engine-specific JSON functions. Job claiming is serialised through one dispatcher process.
