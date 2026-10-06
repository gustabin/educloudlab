# Workspace explorer

| | |
|---|---|
| **Role(s)** | Any member. org_admin sees the whole tenant; others see their own workspaces. |
| **Primary task** | Find, create and open workspaces. |
| **Route** | `/app/workspaces` (session, `read`) |
| **Data** | `GET /api/v1/workspaces?page&per_page=12&q&sort&dir` |

**Actions**

| Action | Calls | Feedback |
|---|---|---|
| Nuevo workspace (modal) | `POST /api/v1/workspaces` | Opens the new workspace. 422 shows inline errors; 409 (duplicate name or quota) shows an error dialog. Hidden without the `create` permission. |
| Search (300 ms debounce) | list endpoint | List refreshes. |
| Sort | list endpoint | List refreshes. |
| Pagination | list endpoint | Shown only when there is more than one page. |

**States**
- **Loading:** three skeleton cards with `aria-busy="true"`.
- **Empty:** "Aún no tienes workspaces" plus guidance. A search with no results shows a separate message.
- **Error:** alert with a "Reintentar" button. Network and timeout errors also show a dialog through `api.js`.

**Acceptance criteria**
- Given a student with no workspaces, when they open the page, then the empty state is shown and no errors are logged in the console.
- Given a name shorter than 2 characters, when the student submits the form, then an inline error appears under the field.
- Given a valid name, when the student submits, then they are taken to the new workspace's page.
