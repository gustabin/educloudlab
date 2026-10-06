# Execution plane (M4)

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
| `profile` | CSV upload | `profile`: schema, row count, preview (50 rows) | version `ready` with its schema; resource `provisioning → active` (or `failed` with a safe error) |
| `ingest` | `POST /datasets/{id}/ingest` | `ingest`: `CREATE OR REPLACE TABLE bronze.<table>` with normalised columns | bronze dataset version `ready`; resource `active` |
| `cleanup` | `DELETE /datasets/{id}` | raw: none (PHP deletes files); table: `drop_table` | files and previews removed; resource `deleting → deleted` |

Payloads contain **internal ids only**. Handlers compute storage paths from DB values, never from user input.

## Dispatcher (`app/Modules/Jobs/Dispatcher.php`, `RunnerProcess.php`)

- **One process, one job at a time.** This also serialises writes to each workspace lakehouse, since DuckDB allows a single writer. Start it with `php scripts/dispatcher.php`, or `--once` to drain the queue.
- **Fair claiming:** among queued jobs, the user whose last job started longest ago goes first, so one user's burst cannot starve others. Per-user quotas (3 active jobs, storage, datasets) are re-checked inside the enqueue transaction under a lock on the user's membership row.
- **Process launch:** `proc_open` with an argument array and `bypass_shell` (no shell). Python runs in isolated mode `-I`.
- **Environment:** only `PATH`, `SYSTEMROOT`, `TEMP`/`TMP` and `WINDIR` are passed.
- **I/O:** request and response files live in `STORAGE_PATH/jobs` and are deleted after every job. Windows pipes cannot be `select()`ed, so files are used instead.
- **Timeouts:** wall-clock per type (`config/execution.php`: profile 60 s, ingest 120 s, cleanup 60 s). The whole process tree is killed (`taskkill /T /F`), and the job ends `timed_out`.
- **Response cap:** 2 MB. A heartbeat is written every 5 s.
- **Stale jobs:** a running job with no heartbeat for `timeout + 60 s` is failed by the scheduler (`INTERRUPTED`).

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
- **Student SQL** is not executed by any M4 op. The SQL Lab sandbox (read-only connection, external access disabled, SELECT-only) arrives in M5.

## Storage layout (`app/Core/Storage/LocalStorage.php`)

```
{STORAGE_PATH}/t/{tenant}/w/{workspace}/raw/{storage_key}.csv
{STORAGE_PATH}/t/{tenant}/w/{workspace}/meta/{version}.preview.json
{STORAGE_PATH}/t/{tenant}/w/{workspace}/lakehouse.duckdb      (schemas bronze, silver, gold)
{STORAGE_PATH}/jobs/  tmp/  locks/  logs/  mail/
```

Every component is a server-generated ULID, and every path is checked to remain inside the root.

## Uploads (`CsvUploadValidator`)

**Checks:**
- genuine upload (`is_uploaded_file` / `move_uploaded_file`);
- 1 byte to 20 MB;
- `.csv` extension;
- `finfo` MIME allowlist;
- no binary signatures (zip, xlsx, pdf, exe, elf, png, gif, jpeg, ole, rar, gzip, 7z, parquet, sqlite);
- no NUL bytes and valid UTF-8 in the **whole** file (streamed in 1 MB chunks; a BOM is allowed).

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
| One dispatcher process is a throughput bottleneck | Fair claiming + per-user job quota | Several workers with per-workspace locking (scalability roadmap) |
