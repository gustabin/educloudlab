# Courses (M10a, minimal)

Instructors in an **organization tenant** group labs into a course, invite students with a join code, and follow their progress. Instructor visibility of student work is **course-scoped**.

## Model

| Table | Notes |
|---|---|
| `courses` | Tenant-owned. `code` is unique per organization. Status `draft → published ⇄ archived`. `join_code_hash` is the SHA-256 of the normalised code and is unique; `join_enabled` turns joining on or off. |
| `enrollments` | `(course, user)` with role `student` or `instructor` and status `active`, `dropped` or `completed` |
| `course_labs` | Labs assigned to a course: position, required flag, `due_at` (end of day, UTC) |
| `lab_attempts.course_id` | Set when a student starts an assigned lab: either an explicit `course_id`, or the **only** course of the active tenant where they are an active student and the lab is assigned |

Organizations and their instructors are managed with the CLI until the admin UI (M11a) exists:

```
php scripts/org.php create "Universidad Demo" admin@example.com
php scripts/org.php add-member <org_id> profesora@example.com instructor
php scripts/org.php list
```

## Roles and visibility (inside the active tenant)

| Who | Courses | Manage (assign permission) | Progress and attempts (review permission) |
|---|---|---|---|
| org_admin, platform admin | All | All | All |
| Course owner, or an active `instructor` enrollment | Their courses | Their courses | Only attempts with `course_id` of their courses |
| Instructor not teaching the course | — (404) | — | — (404, also for attempt ids) |
| Active student enrollment | Published and archived courses they are enrolled in; never drafts | 403 | 403 (route permission) |

Personal (non-course) attempts stay private to their owner and tenant-wide roles. `TenantIsolationTest` attacks every `{course_id}` and `{attempt_id}` route with an instructor of the same organization who does not teach the course.

## Join codes

- **Format:** 10 characters from an unambiguous alphabet (no 0/O/1/I), shown as `XXXXX-XXXXX`, about 1.1 × 10¹⁵ combinations.
- **Storage:** the code is shown **once** when generated. Only its hash is stored, and generating a new one invalidates the old one.
- **Input:** case, spaces and hyphens are ignored.
- **`POST /courses/join`:**
  - Works from **any tenant**.
  - Adds a `student` membership to the course's organization if the user has none; an existing role is never changed.
  - Enrolls the student. A `completed` enrollment becomes active again; a `dropped` one never does.
  - The browser then switches to that organization.
- **Generic failure:** invalid, rotated, disabled, draft or archived codes all return the same 422. So do suspended members and dropped students, so the response never confirms whether a code is valid.
- **Limits:**
  - Rate limits: 30 per 15 min per IP (`course_join_ip`) and 10 per 15 min per user (`course_join_user`).
  - Failed attempts are audited.
  - The owner and instructors cannot join their own course as students (409).

## Progress grid

`GET /courses/{id}/progress` returns active students × assigned labs.
- **Cells:** each cell holds the best course attempt (ties go to the most recent): status, best score, number of submissions, and the number of tasks failed in its latest graded submission.
- **Drill-down:** opens `/app/lab-attempts/{id}` read-only, showing task results, feedback, saved SQL answers and revealed hints. Mutations stay owner-only (403).
- **Summary per lab:** students started, completed, and the average best score.

## M10a security gate (2026-10-07): PASS

No Critical, High or Medium findings. The four Low findings are fixed:

| Finding | Fix |
|---|---|
| Suspended members got 403 only when the code was valid (validity oracle) | Same 422 as a wrong code, and the attempt is audited as denied |
| A dropped student could re-enroll with a code they still knew | `dropped` enrollments are never re-activated by joining |
| `scripts/org.php add-member` lifted a suspension when changing the role | A role change keeps the membership status |
| The isolation matrix accepted any 403 for roles lacking the route permission | The same attacker must also get the same 403 for a nonexistent id |

## Deferred

- Co-instructor management UI, dropping students and CSV export: M10b.
- Modules and lessons, notifications for due dates: release 1.2.
- Organization and member administration UI: M11a.
