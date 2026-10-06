# Workspace detail and resources

| | |
|---|---|
| **Role(s)** | The owner, or org_admin. Other users get the 404 page. |
| **Primary task** | Manage the resources (storage, lakehouse) of one workspace. |
| **Route** | `/app/workspaces/{workspace_id}` (session, `read`). The server resolves visibility and returns 404 when the workspace is not visible. |
| **Data** | Workspace (server-rendered) and `GET /api/v1/workspaces/{id}/resources` |

**Actions**

| Action | Calls | Feedback |
|---|---|---|
| Editar (modal) | `PATCH /api/v1/workspaces/{id}` | Title, breadcrumb and description update in place. |
| Eliminar workspace | `DELETE /api/v1/workspaces/{id}` | Asks the user to type the exact name, then returns to the list. |
| Nuevo recurso (modal) | `POST /api/v1/workspaces/{id}/resources` | Fields: type (radio), name, simulated region, and per-type configuration (only the type's fields are shown). The table refreshes. |
| Eliminar recurso | `DELETE /api/v1/resources/{id}` | Asks the user to type the exact name, then refreshes the table. The live region announces the result. |

**States**
- **Loading:** skeleton.
- **Empty:** "Este workspace no tiene recursos".
- **Error:** alert with "Reintentar".
- **Status badges** are not based on colour alone; each one carries its text.

**Accessibility**
- Modals have labelled titles, and focus moves to the first field.
- Icon-only delete buttons carry an `aria-label` that includes the resource name.
- The table uses `scope="col"` headers.

**Acceptance criteria**
- Given a storage resource created with tier cool and versioning on, then its table row shows `access_tier: cool · versioning: sí`.
- Given a resource, when the user types a confirmation text that does not match its name, then the resource is not deleted and the validation message is shown.
- Given a description containing markup, then it is shown as literal text and never interpreted as HTML.
