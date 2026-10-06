# ADR-010: Notebooks only inside a Docker sandbox

- Status: Accepted (applies to release 1.3)

## Decision
- Student Python runs only in ephemeral containers started with `--network none`, a read-only root filesystem, memory, CPU and pids limits, `cap-drop ALL`, `no-new-privileges` and a non-root user.
- Until the isolation security suite passes, notebooks are available only in a read-only demonstration mode.
