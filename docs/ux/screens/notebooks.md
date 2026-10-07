# Notebooks

## `/app/workspaces/{id}/notebooks` — notebook editor and runs

| | |
|---|---|
| **Role(s)** | Workspace owner (and org_admin) edit and run. Members who can only read see the cells and the last results, without edit or run buttons. |
| **Primary task** | Write code and text cells, run them in the isolated environment, and read the outputs and saved results. |
| **Data** | `GET /api/v1/workspaces/{id}/notebooks` (with `meta.mode`), `GET /api/v1/notebooks/{id}`, `GET /api/v1/notebooks/{id}/runs`, `GET /api/v1/notebook-runs/{id}` (polled while queued or running). |

| Action | Calls | Feedback |
|---|---|---|
| Nuevo notebook | none (template with a text cell and a code cell) | The editor opens. |
| Código / Texto | none | Adds a cell at the end. Each cell can be moved up or down and removed (labelled buttons). |
| Guardar | `POST` or `PATCH` | Announcement. 422 problems listed with their cell path (`cells/2/source`). |
| Ejecutar todo | save, then `POST /notebooks/{id}/runs` | Status line "Ejecutando en el entorno aislado…". Outputs appear under each cell; errors show type, line and message; saved results appear as tables. |
| Eliminar | `DELETE` | Confirmation dialog. 409 while running. |

**Demo mode:** an info banner explains that execution is disabled, and **Ejecutar todo** is disabled with an explanation (`aria-disabled`).

**Accessibility:**
- The CodeMirror inputs are labelled by their cell heading.
- Outputs are `<pre>` text.
- Tables have hidden captions and focusable scroll regions.
- The status line is `aria-live`.

**Security:** every output is student-produced text, rendered with `.text()` and DOM-built tables, never as HTML.

**Acceptance criteria**
- In docker mode, a notebook that reads `bronze.x` and saves a result finishes "Correcto" and shows the table and the artifact.
- A cell that raises shows its error, and later cells do not run.
- In demo mode, Run is disabled and the API answers 409 `NOTEBOOKS_DISABLED`.
