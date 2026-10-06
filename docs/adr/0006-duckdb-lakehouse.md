# ADR-006: Per-workspace DuckDB lakehouse; Spark deferred

- Status: Accepted

## Decision
- Each workspace has a `lakehouse.duckdb` file with `bronze`, `silver` and `gold` schemas. Raw uploads stay as files.
- Student queries use a read-only connection with `enable_external_access=false` and a locked configuration.
- Silver and gold tables are created by wrapping a validated single SELECT in `CREATE OR REPLACE TABLE`.
- Spark is deferred to a future phase.

## Consequences
DuckDB allows a single writer, so write jobs are serialised per workspace.
