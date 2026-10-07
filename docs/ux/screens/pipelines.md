# Pipelines

## `/app/workspaces/{id}/pipelines` — pipeline designer

| | |
|---|---|
| **Role(s)** | Workspace owner (and org_admin). Members who can only read see the list, definitions and runs, without edit or run buttons. |
| **Primary task** | Write a pipeline definition, validate it, save it, run it, and read its per-step report. |
| **Data** | `GET /api/v1/workspaces/{id}/pipelines`, `GET /api/v1/pipelines/{id}/runs`, `GET /api/v1/pipeline-runs/{id}` (polling while queued or running). |

| Action | Calls | Feedback |
|---|---|---|
| Insertar plantilla | none (client templates) | Replaces the editor content. |
| Validar | `POST /api/v1/pipeline-definitions/validate` | Success announcement (node count, output table). A 422 lists each error with its path (`definition/nodes/1/...`). Invalid JSON is caught locally before any request. |
| Guardar | `POST` or `PATCH` | Success toast; the version increases. 422 is reported as for Validar. |
| Ejecutar | `POST /api/v1/pipelines/{id}/runs` | The run appears at the top of the history as "En cola" and is polled. 409 (already running, or output owned by another object) and quota 409s show a dialog. |
| Cancelar ejecución | `POST /api/v1/pipeline-runs/{id}/cancel` | The button changes to "Cancelando…" until the final status. |
| Eliminar | `DELETE /api/v1/pipelines/{id}` | Confirmation dialog. 409 while a run is active. |

**Run report:** a table of steps (id, type, status, rows, ms, message). Status badges are colour plus text. Skipped steps are listed after a failing one.

**States**
- **Loading:** skeleton in the list.
- **Empty:** "Orquesta tus transformaciones" with a call to action.
- **No runs:** "Aún no has ejecutado este pipeline".
- **Error:** dialogs come from `api.js`. Runner errors show the safe message only.

**Acceptance criteria**
- Given a valid definition, when the owner saves and runs it, then the run ends "Correcto" and the output table appears under Datasets with its lineage.
- Given a quality check with `on_fail: stop` that fails, when the pipeline runs, then the run ends "Fallido", the failing step shows the rule message, and no output table is created.
- Given another tenant's pipeline id, when it is opened, then the page and API answer 404.
