# Performance targets and measurements

Measured numbers are labelled **measured**; everything else is a target or an estimate.

## SQL Lab (M5-T07)

**Measured on 2026-10-06** on the development machine:
- Windows 10, XAMPP Apache + PHP 8.1, MariaDB 10.4, Python 3.11 + DuckDB 1.5.6.
- One dispatcher, polling every 200 ms when idle.
- 20 sequential queries (`GROUP BY` over 121 rows) via HTTP with a Bearer token; client polling every 50 ms.

| Metric | p50 | p95 | max |
|---|---|---|---|
| End to end, POST /queries → status succeeded | 1,202 ms | 1,852 ms | 1,864 ms |
| DuckDB execution (`duration_ms`) | 3 ms | 4 ms | 4 ms |

**Interpretation:** almost all of the latency is the cold start of a new Python process plus `import duckdb` per job (ADR-005: short-lived, credential-less runner). Engine time is negligible at educational data sizes.

**MVP targets** (small queries, single user):
- p95 end to end ≤ 2.5 s;
- engine ≤ 10 s, which is a hard limit (`sql_timeout_s`).

**Future optimisation (not in the MVP):** a pool of warm runner processes that serves several jobs while keeping the same sandbox per job. This would cut about 1 s per query. Concurrency today is one job at a time, with fair claiming across users.

## Not yet measured

- Page load times.
- Upload profiling and ingestion time versus file size.
- Behaviour under several concurrent users.

These are measured in M11a (hardening) before targets are set.
