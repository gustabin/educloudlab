# Courses (list, detail, progress)

## `/app/courses` — my courses

| | |
|---|---|
| **Role(s)** | Every member. Instructors and org_admins in an organization also get "Nuevo curso". |
| **Primary task** | Open a course, join one with a code, or create one. |
| **Data** | Server-rendered from `CourseService::list()`, the same data as `GET /api/v1/courses`. |

| Action | Calls | Feedback |
|---|---|---|
| Unirme (code form) | `POST /api/v1/courses/join` | Success dialog naming the course and organization, then a tenant switch (`POST /tenants/{id}/switch`) and redirect to the course. 422 shows an inline error under the field; 429 shows the rate-limit dialog. |
| Nuevo curso (modal) | `POST /api/v1/courses` | Redirects to the course. 422 errors are shown inline; 409 (duplicate code) shows a dialog. |

**States**
- **Empty:** the message depends on the tenant. Personal tenants explain that courses belong to organizations.
- **Error:** dialogs come from `api.js`.

## `/app/courses/{id}` — course

- **Students:**
  - The list of assigned labs shows difficulty, minutes, required or optional, and due date.
  - Each lab shows the student's status and best score, plus **Empezar** (`POST /lab-attempts {lab_code, course_id}`) or **Continuar**.
- **Staff with `assign`:** each lab also has a remove button (confirmation dialog). A side panel offers:
  - **Acceso de estudiantes:** publish, generate the code (confirmation; the new code is shown once in a dialog), disable the code, archive or reopen.
  - **Asignar laboratorio:** form with lab, due date and required flag.
- **Staff with `review`:** a **Progreso** button.

## `/app/courses/{id}/progress` — progress grid

- **Table:** students (row headers) × labs (column headers, with `<abbr>` holding the full title). The table has a visually hidden caption.
- **Cell:** "best/max" linking to the read-only attempt review, plus "n ejercicios sin superar"; not-started cells show "—" with hidden text for screen readers.
- **Footer:** completed out of started, and the average.
- **Empty states:** "no labs assigned" or "no students yet".

**Acceptance criteria**
- Given a student with a valid code, when they join from their personal space, then they land on the course page inside the organization and can start its labs.
- Given a teacher of the course, when a student validates a course lab, then the grid shows the best score and failed task count, and the cell opens the attempt read-only.
- Given an instructor who does not teach the course, when they open the course, progress or attempt URLs, then they get 404.

## Course content (M10b)

### `/app/courses/{id}` — "Contenido" section

- **Students:**
  - They see the published modules and lessons, in order.
  - Each lesson shows a completed/pending icon (with hidden text), the minutes, the due date and its linked lab.
- **Staff with `assign`:**
  - "Nuevo módulo" opens a dialog with title and summary fields.
  - Per module: move up/down, Publicar/Retirar, edit, a "+" button (new lesson: asks for a title, then opens the lesson page to write it) and delete.
  - Per lesson: move up/down, Publicar/Retirar and delete.
  - Drafts carry a "Borrador" badge.
  - Every icon button has an `aria-label` naming its item.
- **Errors:** dialogs. Examples: 409 `MODULE_NOT_EMPTY`; 422 for a lab that is not assigned to the course.
- **Empty:** a message adapted to the role.

### `/app/lessons/{id}` — lesson

- **Content:** breadcrumb, module name, title, minutes and due date, then the rendered Markdown (headings, lists, tables, code).
- **Linked lab:** a card with "Empezar" or "Continuar".
- **Navigation:** Anterior / Siguiente, following the visible order.
- **Students:** "Marcar como completada" (with `aria-pressed`; press again to undo).
- **Staff:** an edit form with title, minutes, due date, linked lab (only labs assigned to the course) and a Markdown textarea with help text. Validation errors are shown inline.
- **Not visible to the caller:** 404 page.

### Notification bell (top bar, every app page)

- **Button:** its label includes the unread count ("Notificaciones (2 sin leer)"); a red badge shows the number (9+ at most).
- **Dropdown:**
  - lists title, body and date, with unread items highlighted (and prefixed with hidden text "Sin leer");
  - clicking an item marks it as read and opens its link;
  - "Marcar todo como leído" marks everything as read.
- **Empty:** "No tienes notificaciones."
- **Failures:** loading errors are silent, because the bell is optional chrome.

**Acceptance criteria**
- Given a draft lesson, when an enrolled student requests it, then they get 404. Once the lesson and its module are published, the student sees it and receives one notification.
- Given a lesson due in less than 24 h that a student has not completed, when the scheduler runs, then the student gets one reminder, and no second one on the next run.
- Given Markdown with `<script>` or `javascript:` links, when the lesson is rendered, then the markup is shown as text and the link is removed.
