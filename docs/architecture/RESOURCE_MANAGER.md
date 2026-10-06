# Educational Resource Manager (M3)

Hierarchy: **Tenant (organization or personal) → Workspace → Resource**. This teaches resource groups, ownership, regions, lifecycle and RBAC without real cloud infrastructure.

## Tenancy and visibility

- **Active tenant:** the session's tenant, or the JWT `tid` claim. It is re-validated against `memberships` on every request. Switch it with `POST /api/v1/tenants/{tenant_id}/switch` (browser sessions only).
- **Visibility** (`app/Core/Auth/Policy.php`):
  - Owners see and manage their own objects.
  - `org_admin` and platform admins see and manage the whole tenant.
  - Instructors get course-scoped visibility in M10a.
- **Invisible or other-tenant objects return 404**, never 403. `TenantIsolationTest` enforces this for every route with an id parameter (release-blocking). It runs four attackers:
  - another tenant via Bearer token;
  - another tenant via browser session;
  - another student of the same organization;
  - an instructor of the same organization.

  After each attempt it checks the table checksums to confirm nothing changed.

## Workspaces

- Name: 2–80 characters, letters, digits, space, `.`, `_`, `-`. Unique per owner among non-deleted workspaces.
- Description: free text, always HTML-escaped on output.
- Quota: 5 active workspaces per user per tenant (`config/quotas.php`). Create and rename run in a transaction that locks the owner's membership row (`SELECT … FOR UPDATE`). This row always exists, so there is no gap-lock deadlock.
- Delete is soft: the workspace becomes `deleted` and its live resources move to `deleting`, following the lifecycle. The cleanup job (M4/M11) releases their storage and moves them to `deleted`.
- Writes are throttled per user: `write_user` allows 60 unsafe requests per minute.

## Resources

| Type | Created via | Config (allowlisted) |
|---|---|---|
| `storage` | `POST /workspaces/{id}/resources` | `access_tier` hot\|cool\|archive, `versioning` bool, `redundancy` lrs\|zrs |
| `lakehouse` | same | `default_layer` bronze\|silver\|gold |
| `dataset` | Datasets module (M4) | — |
| `pipeline`, `notebook`, `dashboard` | later milestones | — |

Rules:
- **Regions** are simulated: `edu-local-1`, `edu-local-2`.
- **Tags:** at most 10, keys `^[a-z0-9_-]{1,32}$`, values up to 64 characters.
- **Quota:** 20 resources per workspace.

**Lifecycle** (`ResourceLifecycle`), with compare-and-set status updates:

```
provisioning ──► active ──► deleting ──► deleted
      └────► failed ──────────┘
```

Provisioning is simulated, so `provisioning → active` happens in the same request. Every transition goes through the state machine. Resource create and rename lock the workspace row and re-check that it is still `active`, so a resource cannot be created in a workspace that is being deleted.

## Audit events

`workspace.create|update|delete`, `resource.create|update|delete`, `tenant.switch`, and denied attempts (`outcome = denied`).
