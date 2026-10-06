---
name: data-execution
description: EduCloud Lab execution plane - job queue, PHP dispatcher, Python/DuckDB runner, lakehouse layers, SQL sandbox and limits. Use when adding or changing job types, runner ops, ingestion, SQL Lab execution, transforms or pipeline execution.
---

# Data Execution Plane

## Architecture (ADR-005, ADR-006)
1. PHP writes a `jobs` row (status `queued`).
2. `scripts/dispatcher.php` claims it with `SELECT ... FOR UPDATE` and runs `worker/.venv/Scripts/python worker/runner.py`.
3. The runner reads one JSON request on stdin and writes one JSON response on stdout.
4. The dispatcher enforces the wall-clock timeout (kills the tree with `taskkill /T /F` on Windows), caps stdout bytes, and stores the status and a safe result.

## Runner contract
- Request: `{"op": "...", "workspace_db": "<abs path>", "inputs": [...], "sql": "...", "limits": {"timeout_s", "max_rows", "max_bytes", "max_memory", "threads"}}`
- Response: `{"ok": true, "data": ..., "stats": {...}}` or `{"ok": false, "error_code": "...", "safe_message": "..."}`
- All paths come from the dispatcher (built from DB values). The runner never receives user paths, DB credentials or secrets. Its environment is scrubbed to PATH, TEMP and SYSTEMROOT.

## SQL sandbox (student SQL)
- Read-only connection to the workspace `lakehouse.duckdb` only.
- `enable_external_access=false`, `autoinstall_known_extensions=false`, `autoload_known_extensions=false`, `max_memory`, `threads`, then `lock_configuration=true`.
- Exactly one statement, and its type must be SELECT (`duckdb.extract_statements`). Reject COPY, ATTACH, INSTALL, LOAD, PRAGMA, SET, EXPORT, CALL, CREATE and similar.
- Transforms wrap a validated single SELECT as `CREATE OR REPLACE TABLE <layer>.<identifier> AS ...`. The identifier must match `^[a-z][a-z0-9_]{0,62}$` and the layer must be silver or gold.
- Write jobs are serialised per workspace (DuckDB single writer).
- Error messages are stripped of filesystem paths before returning.

## Adding an op — workflow
1. Define the request/response in `worker/ops/<op>.py` + register it in `runner.py`.
2. Add the job type + limits in `config/quotas.php` and `Jobs` enqueue validation.
3. pytest: success, limit exceeded, timeout, malicious input. Extend `tests/Security/SqlSandboxTest` for anything that touches SQL.
4. Document it in `docs/architecture/EXECUTION.md`.

## Never
- Execute arbitrary Python outside the Docker sandbox (ADR-010).
- Open MySQL from the runner.
- Trust client-reported results.
