# Object storage browser

## `/app/resources/{id}/storage` — containers and objects

| | |
|---|---|
| **Role(s)** | Resource owner (and org_admin) can edit. Other members who can see the resource get a read-only view (list, download). |
| **Primary task** | Create containers, upload objects with keys, metadata and tier, set lifecycle policies, and download or delete objects. |
| **Data** | `GET /api/v1/resources/{id}/containers` and `GET /api/v1/containers/{id}?prefix=`. The page is reached from the "Abrir" button of a storage resource in the workspace. Other resource types return 404. |

| Action | Calls | Feedback |
|---|---|---|
| Crear contenedor | `POST /api/v1/resources/{id}/containers` | The new container opens. 422 shows an inline error under the name (with the naming rule as help text); 409 (duplicate or quota) shows a dialog. |
| Guardar política | `PATCH /api/v1/containers/{id}` | Announcement; the summary line shows "archivar a los N días, eliminar a los M días". Empty fields remove the policy. |
| Subir | `POST /api/v1/containers/{id}/objects` (multipart) | Announcement; the list refreshes. The key defaults to the file name. 422 errors are shown inline (key, file, metadata, tier). |
| Nivel (select per row) | `PATCH /api/v1/objects/{id}` | The list refreshes. |
| Descargar | Navigation to `/api/v1/objects/{id}/download` | The browser saves an attachment. Disabled, with a tooltip, for archive objects. |
| Eliminar objeto | `DELETE /api/v1/objects/{id}` | Confirmation dialog. |
| Eliminar contenedor | `DELETE /api/v1/containers/{id}` | 409 dialog when the container is not empty. |

**States**
- **Loading:** skeleton in the container list (`aria-busy`).
- **No container selected:** an empty-state panel.
- **No containers / no objects:** a message (also when the prefix filter matches nothing).
- **Error:** dialogs come from `api.js`.

**Accessibility:** every icon button has an `aria-label` naming the object key. The per-row tier select is labelled "Nivel de <key>". The container list marks the open container with `aria-current`.

**Acceptance criteria**
- Given an object in archive, when the user tries to download it, then the button is disabled and the API answers 409 `OBJECT_ARCHIVED`. After changing the tier to cool, the download works.
- Given a container with a lifecycle policy, when the scheduler runs, then objects older than the thresholds are archived or deleted.
- Given HTML disguised as `.txt`, when it is uploaded, then it is rejected with 422.
