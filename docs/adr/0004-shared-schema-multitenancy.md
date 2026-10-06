# ADR-004: Shared-schema multi-tenancy with composite foreign keys

- Status: Accepted

## Options
Shared tables with `tenant_id`, schema-per-tenant, or database-per-tenant.

## Decision
Shared tables with `tenant_id`, enforced at three layers:
1. Middleware derives the tenant from the user's membership.
2. Repositories require a `TenantContext`.
3. Composite foreign keys such as `(tenant_id, workspace_id)` make cross-tenant references impossible at the database level.

Cross-tenant access returns 404.

## Consequences
This is the simplest option to operate. Isolation depends on discipline, which a release-blocking route-matrix test enforces.
