# ADR-010: Notebooks only inside a Docker sandbox

- Status: Accepted (applies to release 1.3)

## Decision
- Student Python runs only in ephemeral containers started with `--network none`, a read-only root filesystem, memory, CPU and pids limits, `cap-drop ALL`, `no-new-privileges` and a non-root user.
- Until the isolation security suite passes, notebooks are available only in a read-only demonstration mode.

## Implementation (M8, 2026-10-07)
- `NOTEBOOKS_MODE` = off | demo (default) | docker. The suite `tests/Sandbox/NotebookIsolationTest.php` passed 15/15 against the real image (after the M8 security gate redesign: detached containers with rotated logs, a PID 1 `timeout` guard, an orphan reaper and a dedicated notebook worker); exact flags and residual risks are in `docs/architecture/NOTEBOOKS.md`.
- The image is pinned by base digest with hash-checked dependencies; results return on stdout only (no writable host mount).
- Results printed by the executor are self-reported by student code. They are normalised by the host and used only for the student's own run and grade: lab grading recomputes the expected rows with SQL, so forging gives no more than `save_result` with literal data.
