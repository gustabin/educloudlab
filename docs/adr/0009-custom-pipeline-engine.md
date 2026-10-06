# ADR-009: Minimal custom pipeline engine

- Status: Accepted (applies to release 1.1)

## Options
Airflow, Dagster, Prefect, or a custom engine.

## Decision
A linear JSON pipeline whose allowlisted node types compile to DuckDB SQL templates. The existing engines are too heavy for XAMPP and need their own servers and databases.
