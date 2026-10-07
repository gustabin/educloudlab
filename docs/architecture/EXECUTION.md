# Execution plane (M4, extended in M7)

Implements ADR-005 (queue + dispatcher + credential-less runner) and ADR-006 (per-workspace DuckDB lakehouse).

```mermaid
flowchart LR
  API[PHP API] -->|INSERT jobs (queued), 202| DB[(MariaDB)]
  D[scripts/dispatcher.php<br/>single instance, lock file] -->|claim FOR UPDATE| DB
  D -->|request.json| R[python -I worker/runner.py<br/>no DB credentials, scrubbed env]
  R -->|reads/writes only below STORAGE_PATH| FS[(raw files, lakehouse.duckdb, previews)]
  R -->|response.json| D
  D -->|handler.succeeded / failed| DB
  S[scripts/scheduler.php<br/>every 5 min] -->|stale jobs, released workspaces, purges| DB
```

## Job types

| Type | Created by | Runner op | Result applied |
|---|---|---|---|
| `profile` | Upload (CSV, JSON/JSONL/NDJSON or Parquet since M7) | `profile`: schema, row count, preview (50 rows), detected format | version `ready` with its schema; resource `provisioning → active` (or `failed` with a safe error) |
| `ingest` | `POST /datasets/{id}/ingest` | `ingest`: `CREATE OR REPLACE TABLE bronze.<table>` with normalised columns | bronze dataset version `ready`; resource `active` |
| `cleanup` | `DELETE /datasets/{id}` | raw: none (PHP deletes files); table: `drop_table` | files and previews removed; resource `deleting → deleted` |
| `sql_query` (priority 1) | `POST /workspaces/{id}/queries` | `query`: student SELECT, read-only, no file access | result file `meta/results/{query}.json` (24 h); `query_history` status, duration and rows |
| `transform` (priority 3) | `POST /workspaces/{id}/transforms` | `transform`: `CREATE OR REPLACE TABLE silver\|gold.<t> AS <SELECT>` | silver/gold dataset `ready`, with the defining SQL kept in its config |
| `pipeline_run` (M7, priority 3) | `POST /pipelines/{id}/runs` | `pipeline`: compiles the node chain into parameterised DuckDB SQL, one TEMP table per step, writes `silver\|gold.<t>` | run status + per-step report (also on failure), output dataset version `ready`, lineage edges (`docs/architecture/PIPELINES_AND_STORAGE.md`) |
| `semantic_query` (M9, priority 1) | `POST /semantic-models/{id}/query`, `POST /dashboards/{id}/render` | `semantic`: compiles each request from the semantic model (fact + needed dimension joins, measures, filters bound as parameters), read-only, no file access | results file `meta/results/{query}.json` (24 h); `semantic_queries` status and duration (`docs/architecture/ANALYTICS.md`) |
| `notebook_run` (M8, priority 3) | `POST /notebooks/{id}/runs` (docker mode only) | **No Python runner**: `NotebookRunHandler` implements `CustomExecutor` and runs every code cell in an ephemeral Docker container (`DockerSandbox`) | per-cell outputs and `save_result()` artifacts in `notebook_runs` (`docs/architecture/NOTEBOOKS.md`) |
| `validate` (M6) | `POST /lab-attempts/{id}/submit` | metadata checks in PHP, then `validate`: data checks and saved SQL answers, read-only and sandboxed (none when the lab has only metadata checks) | `lab_task_results`, score, best score and status of the attempt (`docs/architecture/LAB_ENGINE.md`) |

Payloads contain **internal ids only**. Handlers compute storage paths from DB values, never from user input.

## Dispatcher (`app/Modules/Jobs/Dispatcher.php`, `RunnerProcess.php`)

- **One process, one job at a time.** This also serialises writes to each workspace lakehouse, since DuckDB allows a single writer. Start it with `php scripts/dispatcher.php`, or `--once` to drain the queue.
- **Fair claiming:** among queued jobs, the user whose last job started longest ago goes first, so one user's burst cannot starve others. Per-user quotas (3 active jobs, storage, datasets) are re-checked inside the enqueue transaction under a lock on the user's membership row.
- **Process launch:** `proc_open` with an argument array and `bypass_shell` (no shell). Python runs in isolated mode `-I`.
- **Environment:** only `PATH`, `SYSTEMROOT`, `TEMP`/`TMP` and `WINDIR` are passed.
- **I/O:** request and response files live in `STORAGE_PATH/jobs` and are deleted after every job. Windows pipes cannot be `select()`ed, so files are used instead.
- **Timeouts:** wall-clock per type (`config/execution.php`: profile 60 s, ingest 120 s, cleanup 60 s, sql_query 20 s, transform 90 s, validate 150 s, pipeline_run 300 s, semantic_query 60 s, notebook_run 120 s). The whole process tree is killed (`taskkill /T /F`), and the job ends `timed_out`.
- **Response cap:** 2 MB. A heartbeat is written every 5 s.
- **Stale jobs:** a running job with no heartbeat for `timeout + 60 s` is failed by the scheduler (`INTERRUPTED`).
- **Cancellation (M7):** `jobs.cancel_requested` is set by the API. A queued job is finished at once; for a running
  job the heartbeat returns the flag, the runner tree is killed and the job ends `cancelled` (error `CANCELLED`).
- **Retries (M7):** jobs carry `max_attempts` (pipelines: `retries + 1`, at most 3). Only transient codes
  (`RUNNER_ERROR`, `ENGINE_UNAVAILABLE`) are requeued; data and validation errors fail immediately.
- **Partial results (M7):** handlers implementing `PartialResultHandler` receive the runner's `data` on failure
  (the pipeline step report up to the failing step).

## Runner (`worker/`)

- **Install:** `py -3.11 -m venv worker\.venv`, then `worker\.venv\Scripts\python -m pip install --require-hashes -r worker/requirements.txt` (DuckDB 1.5.6, pinned with a hash).
- **Path confinement:** every path is resolved and must stay below `allowed_root`, which is **the job's workspace directory**, not the whole storage root. Identifiers must match `^[a-z][a-z0-9_]{0,62}$`, and layers must be bronze, silver or gold.
- **DuckDB sandbox** (`ops/common.py::configure`), applied to every connection before any SQL:
  - `allowed_directories = [workspace dir]` with `enable_external_access = false`;
  - extension auto-install and auto-load disabled (no httpfs);
  - finally `lock_configuration = true`, so SQL cannot loosen it.

  Tests prove that reading or `COPY`ing outside the directory fails, that `SET` after the lock fails, and that `INSTALL httpfs` fails. This is the base the SQL Lab (M5) builds on.
- **Header pre-check** before DuckDB sees the file: the header must fit in 1 MB, and delimiter count + 1 must not exceed `max_columns`. This prevents expensive schema sniffing on pathological headers.
- **Strict CSV parsing:**
  - The delimiter is detected from the header line (`,` `;` tab `|`).
  - `read_csv(?, delim = ?, header = true, skip = 0, strict_mode = true, null_padding = false, ignore_errors = false)`.
  - `skip = 0` is essential: without it, DuckDB's sniffer can silently skip leading lines when a later row is malformed and drop data. A regression test covers this.
- **Limits:** 500,000 rows, 100 columns, `threads = 2`, `memory_limit = 512MB` (`config/execution.php → limits`).
- **Column names** are normalised to unique snake_case identifiers on ingestion (`Customer ID` → `customer_id`), and the original header is kept as `source_name`.
- **Errors** become stable codes (`CSV_PARSE_ERROR`, `HEADER_TOO_LONG`, `TOO_MANY_ROWS`, `TOO_MANY_COLUMNS`, `NOT_FOUND`, `BAD_REQUEST`, `RUNNER_ERROR`).
  - Their messages contain no filesystem paths: the literal storage and interpreter paths are removed first (paths with spaces included), then any Windows, UNC or POSIX path.
  - The dispatcher logs only the exception class and code.
- **Student SQL** (M5, `ops/sql_ops.py`) is checked in five layers:
  1. **Statement allowlist:** exactly one statement, and `extract_statements` must report type SELECT.
  2. **Denylist:** functions that expose server paths, settings, files or extensions, or that execute SQL held in a string (`duckdb_*`, `current_setting`, `pragma*`, `read_*`, `glob`, `query(…)`, `query_table`, `json_execute_serialized_sql`, …). The match runs on the raw text, so string literals cannot hide calls.
  3. **Connection:** read-only for queries, with `allowed_directories = []` (no file access at all), no extensions and a locked configuration.
  4. **Limits:** a 10 s interrupt (`QUERY_TIMEOUT`), 1,000 rows, 1.5 MB, plus the dispatcher's hard kill at 20 s.
  5. **Output:** values containing the server path are masked, and errors are path-free.

  Transforms wrap the validated SELECT in `CREATE OR REPLACE TABLE` and re-check that it parses as exactly one CREATE. 35 pytest cases (`worker/tests/test_sql_sandbox.py`) cover the escape attempts.
- **M5 security gate (BLOCKED, then fixed):**
  - **High:** a single query (`SELECT repeat('x', 3000000) FROM range(1001)`) peaked at 6,120 MB. DuckDB's `memory_limit` does not cover Python objects.
    - Every result value is now cut to 1,000 characters inside DuckDB (positional-alias wrapper plus `LIMIT`), rows are fetched in batches of 100 against a byte budget, and the runner has an OS memory cap (Windows Job Object / `RLIMIT_AS`, `process_memory_mb = 1280`).
    - The same probe now peaks at 37 MB. `peak_memory_mb` is reported in every runner response.
  - **Medium:** `"query"(…)` and `query/**/(…)` bypassed the text denylist, and `pg_catalog.pg_settings` exposed settings.
    - A parse-tree check (`json_serialize_sql`) now inspects function names and table references, whatever the spelling: no `pg_catalog`/`system` schemas, no file or URL replacement scans.
    - Path-bearing settings (`temp_directory`, `secret_directory`, `home_directory`, `extension_directory`) are set to neutral values before the configuration is locked.
    - Masking is now explicitly best-effort.
  - **Medium:** transforms could fill the disk. Ingest and transforms now enforce `lakehouse_max_mb = 200` per workspace (CHECKPOINT, file size check, DROP on excess → `LAKEHOUSE_FULL`) and `max_columns` on the result. PHP also refuses to enqueue when the lakehouse is already full.
- **Lab grading** (M6, `ops/lab_ops.py`, op `validate`):
  - It reuses the student-SQL sandbox: `validate_select`, read-only connection, `allowed_directories = []`, locked configuration, a 10 s interrupt per statement, and `fetch_bounded` (values cut inside DuckDB, at most 1,000 rows compared).
  - A broken or malicious check fails on its own without aborting the others. 20 pytest cases (`worker/tests/test_lab_validate.py`) cover verdicts, sandboxed answers and malformed checks.
- **Spike findings (DuckDB 1.5.6):**
  - `duckdb_databases()`, `duckdb_settings()` and `current_setting()` reveal server paths even in sandbox mode. They are denylisted.
  - `query('…')` would bypass the statement check. It is denylisted.
  - `con.interrupt()` stops a running query within about 0.5 s.

## Storage layout (`app/Core/Storage/LocalStorage.php`)

```
{STORAGE_PATH}/t/{tenant}/w/{workspace}/raw/{storage_key}.{csv|json|parquet}
{STORAGE_PATH}/t/{tenant}/w/{workspace}/objects/{storage_key}.bin   (object storage, M7; never read by the runner)
{STORAGE_PATH}/t/{tenant}/w/{workspace}/meta/{version}.preview.json
{STORAGE_PATH}/t/{tenant}/w/{workspace}/lakehouse.duckdb      (schemas bronze, silver, gold)
{STORAGE_PATH}/jobs/  tmp/  locks/  logs/  mail/
```

Every component is a server-generated ULID, and every path is checked to remain inside the root.

## Uploads (`DataUploadValidator`, M7)

**Checks:**
- genuine upload (`is_uploaded_file` / `move_uploaded_file`);
- 1 byte to 20 MB;
- extension allowlist: datasets `.csv .json .jsonl .ndjson .parquet`; object storage adds `.txt .md`;
- text formats: `finfo` MIME allowlist, no binary signatures (zip, xlsx, pdf, exe, elf, png, gif, jpeg, ole, rar,
  gzip, 7z, parquet, sqlite), no NUL bytes and valid UTF-8 in the **whole** file (streamed in 1 MB chunks; a BOM is
  allowed); JSON must start with `[` or `{`;
- Parquet: `PAR1` magic at the head **and** the tail; the structure is read only by the runner (`read_parquet`).

**Runner readers (M7):** JSON uses `read_json(?, format = 'auto', maximum_object_size = 1 MB, sample_size = 20480,
maximum_depth = 10)` (deeper nesting is kept as JSON text; the upload cap bounds object size), Parquet uses
`read_parquet(?)`. Errors map to `JSON_PARSE_ERROR` / `PARQUET_ERROR`; the row and column caps apply to every format.

**Quotas:**
- 50 datasets per workspace;
- 200 MB of raw files per user per tenant;
- 3 active jobs per user.

The client file name is stored for display only. CSV cell values such as `=SUM(...)` are kept as data; CSV export escaping is handled when exports exist.

## Operations

| Task | Command |
|---|---|
| Run the dispatcher (keep a console open, or a Windows scheduled task "At startup") | `php scripts/dispatcher.php` |
| Housekeeping every 5 min (Task Scheduler) | `php scripts/scheduler.php` |
| Runner tests | `worker\.venv\Scripts\python -m pytest worker/tests` |

## Accepted residual risks (M4 security gate, 2026-10-06)

| Risk | Today | Planned |
|---|---|---|
| On Linux a timeout kills only the runner PID, not its process group; there is no OS-level memory cap on any platform (DuckDB's `memory_limit` covers the engine only) | Development runs on Windows (`taskkill /T`); single-threaded Python ops | `setsid` + process-group kill, and `RLIMIT_AS` or Docker limits (M8/M12) |
| One dispatcher process is a throughput bottleneck. A student can keep it busy with up to 3 queries, each lasting up to 20 s | Fair claiming across users + per-user active-job quota + `write_user` rate limit | Several workers with per-workspace locking, or warm runner pool (scalability roadmap) |
| Lakehouse bytes are capped per workspace (200 MB), not per user (5 workspaces → 1 GB per user) | Workspace quota (5 per user) | Count lakehouse bytes in the per-user storage quota (M11) |
