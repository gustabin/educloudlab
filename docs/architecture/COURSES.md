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

## Modules, lessons and notifications (M10b, release 1.2)

**Content model.**
- `course_modules` holds ordered modules (`draft|published`).
- `course_lessons` holds ordered lessons inside a module. Each lesson has:
  - a CommonMark body of up to 50,000 characters;
  - estimated minutes;
  - an optional due date (23:59:59 UTC of the chosen day);
  - an optional link to a lab **assigned to the course**;
  - a status, `draft|published`.
- `lesson_progress` stores one completion row per student and lesson.
- Limits: 30 modules per course and 50 lessons per module.

**Visibility.**
- Content builds on the course visibility (`CourseService::findOrFail`).
- Staff see every module and lesson, including the Markdown source.
- An enrolled student sees a lesson only when the course is not a draft and both its module and the lesson are published. The student gets the rendered HTML only.
- Anything else answers **404**, exactly like a missing id. Editing needs course staff with `assign`.

**Rendering.** Lessons use the same converter as the lab instructions (`Core\Markdown`: GFM tables, `html_input: escape`, `allow_unsafe_links: false`), so lesson HTML is printed without `e()`.

**Ordering.** `position` in `PATCH` is a 1-based target. The repository renumbers the siblings inside the course row lock.

**Progress.** Students mark lessons as completed (idempotent) and can undo it. The progress grid adds `lessons_completed` per student and `lessons_total` (published lessons in published modules).

**Notifications (in-app).**
- Stored in `notifications`, unique on `(user_id, kind, ref_key)`, so each event reaches a user once:
  - `lesson_published` is created when a lesson becomes visible: the lesson is published, its module is published, or the course leaves draft;
  - `lesson_due` and `lab_due` are created by `Maintenance` for items due within 24 h that the student has not completed (a completed course attempt, for labs).
- Read notifications are purged after 90 days.
- The API only returns the caller's notifications in the active tenant; marking another user's notification answers 404. Links are app paths built server-side from public ids.
- The top-bar bell (`js/core/notifications.js`) shows the unread count, lists notifications on open, and marks one (on click) or all as read.

## Deferred

- Co-instructor management UI, dropping students and CSV export.
- E-mail notifications and per-user notification preferences.
- Quizzes, attachments in lessons and schedule-based unlocking.

## M10b security gate (2026-10-07): PASS WITH FINDINGS

The gate found 0 Critical or High issues. All findings were fixed before release:

| ID | Severity | Fix |
|---|---|---|
| F1 | Medium | Publish notifications are inserted in multi-row batches (200 per statement) and are best effort. A failure is logged and never turns a committed publish into a 500. The `course.update` audit record is written before the fan-out. |
| F2 | Medium | Optional fields (`due_at`, `estimated_minutes`, `lab_code`, module `summary`) can be cleared with `null` or `""`. Unassigning a lab from the course unlinks it from the course lessons. |
| F3 | Low | Recording lesson progress needs the `create` permission (like starting a lab), so read_only members get 403. |
| F4 | Low | Only published courses notify (no notifications from archived courses); reopening a course notifies. |
| F5 | Low | `ContentRepository::move()` checks its table/column pair against a fixed allowlist. |
| F6 | Low | Unread notifications are also purged after 180 days. |
| F7 | Low | Lesson creation re-checks that the module still exists under the course lock (404 instead of a foreign-key 500). |
| F8 | Low (tests) | The CSRF registry sweep resets rate-limit windows and requires exactly 403 `CSRF_INVALID` for every unsafe route. |
