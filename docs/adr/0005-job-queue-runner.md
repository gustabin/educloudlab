# ADR-005: Job queue, PHP dispatcher and credential-less Python/DuckDB runner

- Status: Accepted

## Context
The spec forbids running student code inside the Apache process (§16).

## Options
In-process execution, a synchronous subprocess per request, a queue with a dispatcher, or microservices.

## Decision
- PHP enqueues jobs in MySQL.
- `scripts/dispatcher.php` (PHP CLI) claims each job and launches a Python runner with JSON over stdin/stdout.
- The dispatcher enforces timeout, process-tree kill and an output cap.
- The runner receives no database credentials and runs with a scrubbed environment.

## Consequences
- Execution is asynchronous, so the UI polls job status.
- On Windows there is no OS sandbox, so the runner only executes DuckDB SQL with external access disabled.
