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
