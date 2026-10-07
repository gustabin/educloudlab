# Lab attempt (lab interface)

| | |
|---|---|
| **Role(s)** | The attempt's owner can do everything. Tenant-wide roles (org_admin) get a read-only view. Everyone else gets 404. |
| **Primary task** | Follow the instructions, do the work in the lab workspace, save SQL answers, ask for hints, validate and read the feedback. |
| **Route** | `/app/lab-attempts/{attempt_id}` (session, `read`). The structure and Markdown instructions are server-rendered; dynamic state comes from `GET /api/v1/lab-attempts/{id}` (`labs.js`). |

**Layout**
- **Left column:** introduction (Markdown, objectives, downloads), then one card per task with:
  - title and points;
  - result area (`aria-live`);
  - instructions;
  - SQL answer form (exercise tasks only);
  - hint buttons.
- **Right column (sticky):** "Tu entorno" panel with:
  - links to the lab workspace and its SQL Lab, and the expiry date;
  - status, last score, number of validations and hints used;
  - "Validar laboratorio" and "Abandonar laboratorio" buttons.

**Actions**

| Action | Calls | Feedback |
|---|---|---|
| Guardar respuesta | `POST …/answers {task_key, sql}` | An inline status message ("Respuesta guardada…") that is also announced. 409 while validating. |
| Ver pista n (−p pts) | Confirmation dialog, then `POST …/hints {task_key, hint_index}` | The button is replaced by the hint text, which receives focus. Hints must be revealed in order (409 otherwise). |
| Validar laboratorio | `POST …/submit {}` → 202 | The button shows a spinner and polling starts (2 s). When it ends, a dialog shows "Puntuación: x / y" and each task shows its verdict and feedback. |
| Abandonar laboratorio | Confirmation dialog, then `DELETE …` → 204 | Redirects to the catalog. |

**States**
- **Preparing:** an info alert while setup jobs are pending (`environment.ready = false`). Validate is disabled and the page polls.
- **Validating:** status badge "Validando…" and a disabled button, with polling.
- **Validation error:** a danger alert with the safe message (e.g. timeout); the attempt returns to its previous status.
- **Closed (abandoned or expired):** a grey alert with a link back to the catalog. Forms are read-only.
- **Load error:** an alert that retries every 5 s.
- **Read-only (org_admin):** student name in the header, read-only textareas, no hint, validate or abandon buttons.

**Accessibility**
- Each task is a `<section>` labelled by its heading.
- Results and status use `aria-live="polite"`, and the final score is also sent to the global live region.
- Focus moves to a revealed hint.

**Acceptance criteria**
- Given a fresh LAB-003 attempt, when the page loads, then the preparing alert is shown until the setup jobs finish, then validation is enabled.
- Given saved answers, when the student validates, then each task shows "Superado (+n pts)" or "Aún no superado" with feedback, and the best score updates.
- Given a hint with penalty 3, when it is revealed and the task later passes, then the task earns its points minus 3, even if the hint is revealed again.
- Given an SQL answer containing HTML, when the page renders, then it appears as text inside the textarea.
