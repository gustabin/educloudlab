# ADR-007: Fixed roles in code

- Status: Accepted

## Decision
- The five roles are a `memberships.role` ENUM.
- The role-to-permission map lives in `config/permissions.php`.
- `roles` and `permissions` tables are introduced only when custom roles are needed (YAGNI).
