# Admin monitor

| | |
|---|---|
| **Role(s)** | Platform administrators only (`users.is_platform_admin`, granted with `php scripts/admin.php grant`). Everyone else gets 403, and the menu entry is hidden. |
| **Primary task** | Check platform health, investigate failed jobs and denied actions, and disable abusive accounts. |
| **Route** | `/app/admin` (session, permission `platform_admin`). Data comes from `GET /api/v1/admin/{overview,jobs,audit,users,health,metrics,logs}` through `admin.js`. |

**Layout**
- **Overview cards:** users (active/disabled), queue (queued, running, failed in 24 h), storage (cached gauges), labs (in progress, completed) and denied actions in 24 h.
- **Tabs with filtered, paginated tables:**
  - Trabajos: filter by status and type.
  - Auditoría: filter by action prefix and outcome.
  - Usuarios: search by email or name, filter by status.
  - Observabilidad (M11b; loaded the first time the tab opens):
    - component cards with a status badge (Correcto, Atención, Caído, Desactivado) and safe details;
    - a period selector (1 h, 24 h, 7 days);
    - KPIs: requests, p95 latency with p50/p99, 5xx and 4xx;
    - a requests/errors line chart with a "Ver datos" table;
    - tables of the slowest, busiest and failing routes, and jobs by type;
    - a log lookup by request id.

**Actions**

| Action | Calls | Feedback |
|---|---|---|
| Desactivar / Reactivar (users tab) | Confirmation dialog, then `PATCH /api/v1/admin/users/{id} {status}` | The list and the overview reload. Disabling ends all the user's sessions and refresh tokens. Platform admins and your own account cannot be changed (403). |
| Actualizar | Reloads every section | — |
| Filters | 300 ms debounce, then reload page 1 | — |
| Periodo (observability) | `GET /api/v1/admin/metrics?window=` | Metrics reload |
| Buscar (log lookup) | `GET /api/v1/admin/logs?request_id=` | Events of that request, newest first. An invalid id is rejected client-side with an inline alert and `aria-invalid`. |

**States**
- **Loading:** skeleton cards; lists have `aria-busy`.
- **Empty:** "No hay resultados."
- **Error:** alert with the safe message.

**Data shown:** no secrets ever: no password hashes, token hashes, job payloads or IP hashes. The `AdminMonitorTest` integration test asserts this.

**Accessibility:** checked with axe-core in `npm run e2e`, including the Observabilidad tab.
- The chart is a canvas with `role="img"` and a summary label, plus a data table alternative.
- Scrollable tables are focusable, labelled regions.
- Status is conveyed by badge text, not colour alone.
