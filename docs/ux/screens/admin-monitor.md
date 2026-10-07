# Admin monitor

| | |
|---|---|
| **Role(s)** | Platform administrators only (`users.is_platform_admin`, granted with `php scripts/admin.php grant`). Everyone else gets 403, and the menu entry is hidden. |
| **Primary task** | Check platform health, investigate failed jobs and denied actions, and disable abusive accounts. |
| **Route** | `/app/admin` (session, permission `platform_admin`). Data comes from `GET /api/v1/admin/{overview,jobs,audit,users}` through `admin.js`. |

**Layout**
- **Overview cards:** users (active/disabled), queue (queued, running, failed in 24 h), storage (cached gauges), labs (in progress, completed) and denied actions in 24 h.
- **Tabs with filtered, paginated tables:**
  - Trabajos: filter by status and type.
  - Auditoría: filter by action prefix and outcome.
  - Usuarios: search by email or name, filter by status.

**Actions**

| Action | Calls | Feedback |
|---|---|---|
| Desactivar / Reactivar (users tab) | Confirmation dialog, then `PATCH /api/v1/admin/users/{id} {status}` | The list and the overview reload. Disabling ends all the user's sessions and refresh tokens. Platform admins and your own account cannot be changed (403). |
| Actualizar | Reloads every section | — |
| Filters | 300 ms debounce, then reload page 1 | — |

**States**
- **Loading:** skeleton cards; lists have `aria-busy`.
- **Empty:** "No hay resultados."
- **Error:** alert with the safe message.

**Data shown:** no secrets ever: no password hashes, token hashes, job payloads or IP hashes. The `AdminMonitorTest` integration test asserts this.

**Accessibility:** checked with axe-core in `npm run e2e`.
