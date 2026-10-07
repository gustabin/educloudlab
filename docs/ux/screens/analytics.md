# Analytics (semantic models and dashboards)

## `/app/workspaces/{id}/analytics` — models and dashboards

| | |
|---|---|
| **Role(s)** | Workspace owner (and org_admin) can edit. Members who can only read see lists and definitions without edit buttons. |
| **Primary task** | Define a semantic model over gold tables, explore it, and build dashboards on it. |
| **Data** | `GET /api/v1/workspaces/{id}/semantic-models`, `GET /api/v1/workspaces/{id}/dashboards`, and the catalog of ready silver/gold tables (server-rendered). |

| Action | Calls | Feedback |
|---|---|---|
| New model / new dashboard (+) | none (client templates) | The editor opens with the first template. A new dashboard needs at least one model (dialog otherwise). |
| Validar (models) | `POST /workspaces/{id}/semantic-models/validate` | Success dialog. A 422 lists each problem with its JSON path. |
| Guardar | `POST` or `PATCH` | Announcement; the lists refresh. A 409 (`MODEL_IN_USE`, duplicate name, quota) is listed in the problems box. |
| Eliminar | `DELETE` | Confirmation dialog. 409 while dashboards use the model. |
| Explorar el modelo | `POST /semantic-models/{id}/query`, then `GET /semantic-queries/{id}` | Status line, then a KPI, bar chart or table drawn with the shared chart helper. |
| Abrir dashboard | navigation | Opens the viewer. |

**States**
- **Loading:** list skeletons.
- **Empty:** "Del dato a la decisión".
- **Empty catalog:** a message pointing to the SQL Lab and pipelines.
- **Errors:** inline problems box or dialogs.

## `/app/dashboards/{id}` — dashboard viewer

| | |
|---|---|
| **Role(s)** | Anyone who can see the dashboard. Rendering needs the `execute` permission on it; otherwise an info message explains it. |
| **Primary task** | Read the KPIs and charts, and filter by date range and dimension values. |
| **Data** | One render job (`POST /dashboards/{id}/render`), polled through `GET /semantic-queries/{id}`. |

- **Filter bar:** date inputs (when the dashboard declares `date_filter`) and selects filled with the distinct values returned by the render. "Aplicar" re-renders every widget at once.
- **Widgets:**
  - KPI cards (large value with its formatted unit);
  - bar and line charts, with `role="img"`, a summary `aria-label` and a "Ver datos" `<details>` table;
  - tables with column headers and a hidden caption.
- **States:** skeleton per widget while busy (`aria-busy`), "No hay datos para estos filtros", a per-widget error message when that query failed, and "El resultado caducó" after 24 h.

**Acceptance criteria**
- Given a valid model and dashboard, when the owner opens the viewer, then every widget renders from a single job, and changing the region filter re-renders them all.
- Given a widget whose table was dropped, when the dashboard renders, then that widget shows its error and the others still render.
- Given another tenant's dashboard id, when it is opened, then the page and API answer 404.
