# Lab catalog

| | |
|---|---|
| **Role(s)** | Any member can browse. Starting a lab needs the `create` permission, so `read_only` users only see the catalog. |
| **Primary task** | Choose a lab and start it, or continue an open attempt. |
| **Route** | `/app/labs` (session, `read`). The cards are server-rendered from `LabService::catalog()`, the same data as `GET /api/v1/labs`. |

**Each card shows**
- Code, title, difficulty badge and summary.
- Minutes, number of exercises, points and prerequisites.
- Objectives, collapsible with `<details>`.
- The caller's status and best score.

**Actions**

| Action | Calls | Feedback |
|---|---|---|
| Empezar / Empezar de nuevo | `POST /api/v1/lab-attempts {lab_code}` | Redirects to `/app/lab-attempts/{id}`. 201 is a new attempt; 200 means an existing open attempt. 409 QUOTA_EXCEEDED (3 labs in progress) shows an error dialog. The button is disabled while the request is in flight. |
| Continuar | link | Opens the open attempt. |

**States**
- **Empty:** "Aún no hay laboratorios publicados" (no labs imported).
- **Loading:** none. The page is server-rendered; the start button shows the in-flight state.
- **Error:** start failures go through `api.js`, which shows a SweetAlert2 dialog with the server message.

**Acceptance criteria**
- Given a student with no attempts, when they open the catalog, then every published lab shows "Sin empezar" and an "Empezar" button.
- Given an open attempt, when the catalog loads, then that lab shows "Continuar" linking to the attempt.
- Given a `read_only` member, when they open the catalog, then no start buttons are shown, and the API returns 403 if called directly.
