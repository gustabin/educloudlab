"""SQL Lab sandbox: every escape attempt must fail, legitimate analytics must work.
(run: worker\\.venv\\Scripts\\python -m pytest worker/tests -q; the security gate selects these with -k sandbox)"""
from __future__ import annotations

import json
import sys
from pathlib import Path

import duckdb
import pytest

WORKER = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WORKER))

from ops.common import RunnerError  # noqa: E402
from ops.sql_ops import query, transform, validate_select  # noqa: E402

LIMITS = {"threads": 1, "memory_mb": 256, "sql_max_rows": 100, "sql_max_bytes": 200_000, "sql_timeout_s": 2,
          "transform_timeout_s": 5, "sql_max_length": 5000, "max_rows": 10_000, "preview_rows": 5}


@pytest.fixture()
def ws(tmp_path: Path) -> Path:
    lake = tmp_path / "lakehouse.duckdb"
    con = duckdb.connect(str(lake))
    con.execute("CREATE SCHEMA bronze; CREATE SCHEMA silver; CREATE SCHEMA gold")
    con.execute("""CREATE TABLE bronze.customers AS SELECT * FROM (VALUES
        (1, 'Ana', 'ana@x.com', 'Lima', 10.5), (2, 'Luis', NULL, 'Quito', 7.0), (3, 'Eva', 'eva@x.com', 'Lima', 3.0)
    ) t(customer_id, name, email, city, amount)""")
    con.close()
    return tmp_path


def run_query(ws: Path, sql: str, limits: dict | None = None) -> dict:
    return query({"lakehouse_path": str(ws / "lakehouse.duckdb"), "sql": sql}, limits or LIMITS, str(ws))


# ---------------------------------------------------------------- legitimate analytics
def test_sandbox_allows_analytical_selects(ws: Path) -> None:
    out = run_query(ws, """
        WITH by_city AS (
            SELECT city, count(*) AS n, sum(amount) AS total FROM bronze.customers GROUP BY city HAVING count(*) >= 1
        )
        SELECT city, n, total, rank() OVER (ORDER BY total DESC) AS pos FROM by_city ORDER BY pos;
    """)
    assert [c["name"] for c in out["columns"]] == ["city", "n", "total", "pos"]
    assert out["rows"][0] == ["Lima", 2, "13.5", 1]  # DECIMAL sums are returned as exact strings
    assert out["truncated"] is False
    assert run_query(ws, "FROM bronze.customers WHERE email IS NULL")["rows"][0][1] == "Luis"
    assert run_query(ws, "DESCRIBE bronze.customers")["row_count"] == 5
    assert run_query(ws, "SELECT table_schema, table_name FROM information_schema.tables")["row_count"] == 1


def test_sandbox_truncates_large_results(ws: Path) -> None:
    out = run_query(ws, "SELECT range FROM range(1000)")
    assert out["row_count"] == 100 and out["truncated"] is True
    big = run_query(ws, "SELECT repeat('x', 900) AS s FROM range(100)", {**LIMITS, "sql_max_bytes": 10_000})
    assert big["truncated"] is True and big["row_count"] < 100


# ---------------------------------------------------------------- statement allowlist / denylist
@pytest.mark.parametrize("sql", [
    "COPY (SELECT 1) TO 'x.csv'",
    "ATTACH 'other.db' AS o",
    "INSTALL httpfs",
    "LOAD httpfs",
    "SET threads = 64",
    "SET enable_external_access = true",
    "CREATE TABLE bronze.x AS SELECT 1",
    "INSERT INTO bronze.customers VALUES (9, 'x', NULL, 'y', 1)",
    "UPDATE bronze.customers SET amount = 0",
    "DELETE FROM bronze.customers",
    "DROP TABLE bronze.customers",
    "EXPORT DATABASE 'out'",
    "CALL pragma_version()",
    "CHECKPOINT",
    "DETACH lakehouse",
    "USE memory",
    "EXPLAIN SELECT 1",
    "SELECT 1; DROP TABLE bronze.customers",
    "SELECT 1; SELECT 2",
])
def test_sandbox_rejects_non_select_statements(ws: Path, sql: str) -> None:
    with pytest.raises(RunnerError) as err:
        run_query(ws, sql)
    assert err.value.code in ("SQL_FORBIDDEN", "SQL_SYNTAX_ERROR")


@pytest.mark.parametrize("sql", [
    "SELECT path FROM duckdb_databases()",
    "SELECT * FROM duckdb_settings()",
    "SELECT current_setting('temp_directory')",
    "PRAGMA database_list",
    "SELECT * FROM pragma_database_list()",
    "SELECT getenv('PATH')",
    "SELECT * FROM read_csv('C:/Windows/win.ini')",
    "SELECT * FROM read_text('/etc/passwd')",
    "SELECT * FROM glob('*')",
    "SELECT * FROM 'C:/Windows/win.ini'",
    "SELECT * FROM 'https://example.com/data.csv'",
    "SELECT * FROM parquet_scan('x.parquet')",
    "SELECT * FROM query('SELECT path FROM duckdb_databases()')",
    "SELECT * FROM QUERY ('SELECT 1')",
    "SELECT * FROM query_table('bronze.customers')",
    'SELECT * FROM "duckdb_databases"()',
    "SELECT json_execute_serialized_sql('{}')",
])
def test_sandbox_blocks_server_introspection_and_file_access(ws: Path, sql: str) -> None:
    with pytest.raises(RunnerError) as err:
        run_query(ws, sql)
    assert err.value.code in ("SQL_FORBIDDEN", "SQL_CATALOG_ERROR", "SQL_ERROR", "SQL_SYNTAX_ERROR")
    assert str(ws) not in err.value.safe_message


def test_sandbox_connection_is_read_only_even_if_validation_were_bypassed(ws: Path) -> None:
    from ops.common import configure
    con = duckdb.connect(str(ws / "lakehouse.duckdb"), read_only=True)
    try:
        configure(con, LIMITS, str(ws), file_access=False)
        with pytest.raises(duckdb.Error):
            con.execute("CREATE TABLE bronze.evil AS SELECT 1")
        with pytest.raises(duckdb.Error):
            con.execute("SELECT * FROM read_csv(?)", [str(ws / "lakehouse.duckdb")]).fetchall()
        with pytest.raises(duckdb.Error):
            con.execute("SET enable_external_access = true")
    finally:
        con.close()


def test_sandbox_interrupts_long_queries(ws: Path) -> None:
    with pytest.raises(RunnerError) as err:
        run_query(ws, "SELECT count(*) FROM range(100000000000) a", {**LIMITS, "sql_timeout_s": 0.5})
    assert err.value.code == "QUERY_TIMEOUT"


def test_sandbox_errors_are_helpful_and_path_free(ws: Path) -> None:
    with pytest.raises(RunnerError) as err:
        run_query(ws, "SELECT nombre FROM bronze.customers")
    assert err.value.code in ("SQL_BINDER_ERROR", "SQL_CATALOG_ERROR")
    assert "nombre" in err.value.safe_message
    with pytest.raises(RunnerError) as err:
        run_query(ws, "SELEC 1")
    assert err.value.code == "SQL_SYNTAX_ERROR"
    with pytest.raises(RunnerError) as err:
        run_query(ws, "SELECT * FROM bronze.no_existe")
    assert err.value.code == "SQL_CATALOG_ERROR"
    assert str(ws) not in err.value.safe_message


def test_sandbox_length_and_empty_checks() -> None:
    with pytest.raises(RunnerError):
        validate_select("   ", 100)
    with pytest.raises(RunnerError) as err:
        validate_select("SELECT 1 " + "x" * 200, 100)
    assert err.value.code == "SQL_TOO_LONG"


def test_sandbox_missing_lakehouse(tmp_path: Path) -> None:
    with pytest.raises(RunnerError) as err:
        query({"lakehouse_path": str(tmp_path / "lakehouse.duckdb"), "sql": "SELECT 1"}, LIMITS, str(tmp_path))
    assert err.value.code == "NO_TABLES"


# ---------------------------------------------------------------- transforms
def test_transform_creates_silver_and_gold_tables(ws: Path) -> None:
    args = {"lakehouse_path": str(ws / "lakehouse.duckdb"), "preview_path": str(ws / "meta" / "p.json")}
    out = transform({**args, "layer": "silver", "table": "customers",
                     "sql": "SELECT customer_id, lower(email) AS email, city FROM bronze.customers WHERE email IS NOT NULL;"},
                    LIMITS, str(ws))
    assert out["row_count"] == 2 and [c["name"] for c in out["columns"]] == ["customer_id", "email", "city"]
    gold = transform({**args, "layer": "gold", "table": "customers_by_city",
                      "sql": "SELECT city, count(*) AS n FROM silver.customers GROUP BY city"}, LIMITS, str(ws))
    assert gold["row_count"] == 1
    assert json.loads((ws / "meta" / "p.json").read_text(encoding="utf-8"))["columns"] == ["city", "n"]


@pytest.mark.parametrize("layer,table,sql,code", [
    ("bronze", "x", "SELECT 1", "BAD_REQUEST"),
    ("main", "x", "SELECT 1", "BAD_REQUEST"),
    ("silver", "X; DROP", "SELECT 1", "BAD_REQUEST"),
    ("silver", "x", "DROP TABLE bronze.customers", "SQL_FORBIDDEN"),
    ("silver", "x", "SELECT 1) ; DROP TABLE bronze.customers; --", "SQL_FORBIDDEN"),
    ("silver", "x", "SELECT * FROM read_csv('x.csv')", "SQL_FORBIDDEN"),
])
def test_sandbox_transform_rejects_bad_input(ws: Path, layer: str, table: str, sql: str, code: str) -> None:
    with pytest.raises(RunnerError) as err:
        transform({"lakehouse_path": str(ws / "lakehouse.duckdb"), "preview_path": str(ws / "p.json"),
                   "layer": layer, "table": table, "sql": sql}, LIMITS, str(ws))
    assert err.value.code in (code, "SQL_SYNTAX_ERROR")
    con = duckdb.connect(str(ws / "lakehouse.duckdb"), read_only=True)
    try:
        assert con.execute("SELECT count(*) FROM bronze.customers").fetchone()[0] == 3
    finally:
        con.close()


# ---------------------------------------------------------------- M5 security gate regressions
@pytest.mark.parametrize("sql", [
    "SELECT * FROM \"query\"('SELECT 42')",
    "SELECT * FROM query/**/('SELECT 43')",
    "SELECT * FROM \"query\"('SELECT name, value FROM duck' || 'db_settings()')",
    "SELECT * FROM \"query\"('SELECT replace(path, chr(92), ''|'') FROM duck' || 'db_databases()')",
    "SELECT reverse(setting) FROM pg_catalog.pg_settings WHERE name = 'temp_directory'",
    "SELECT name, setting FROM pg_settings",
    "SELECT * FROM system.main.duckdb_databases()",
    'SELECT * FROM "DUCKDB_SETTINGS"()',
    "SELECT * FROM 'C:/Windows/win.ini'",
    "SELECT * FROM \"lakehouse.duckdb\"",
    # parse-tree evasions (re-review): macros, lambdas, table functions without parentheses, quoted catalogs
    "CREATE MACRO m() AS current_setting('home_directory')",
    "SELECT list_transform([1], x -> current_setting('home_directory'))",
    "SELECT * FROM duckdb_settings",
    'SELECT * FROM "system"."main"."duckdb_settings"',
    "SELECT * FROM pg_catalog.pg_class",
    "SELECT * FROM \"read_csv_auto\"('x')",
    "SELECT * FROM read_text('x')",
    "SELECT * FROM (FROM 'x.parquet')",
    "SET VARIABLE x = 1",
])
def test_sandbox_gate_bypass_probes_are_blocked(ws: Path, sql: str) -> None:
    with pytest.raises(RunnerError) as err:
        run_query(ws, sql)
    assert err.value.code in ("SQL_FORBIDDEN", "SQL_CATALOG_ERROR")


def test_sandbox_path_settings_are_neutralised(ws: Path) -> None:
    from ops.common import configure
    con = duckdb.connect(str(ws / "lakehouse.duckdb"), read_only=True)
    try:
        configure(con, LIMITS, str(ws), file_access=False)
        settings = dict(con.execute(
            "SELECT name, setting FROM pg_catalog.pg_settings WHERE name IN "
            "('temp_directory','secret_directory','home_directory','extension_directory')").fetchall())
        assert all(str(ws) not in str(v) and ":\\" not in str(v) for v in settings.values()), settings
    finally:
        con.close()


def test_sandbox_wide_values_are_cut_inside_duckdb(ws: Path) -> None:
    out = run_query(ws, "SELECT repeat('x', 50000) AS big, 1 AS n, [1, 2, 3] AS l FROM range(3)")
    assert out["row_count"] == 3
    assert len(out["rows"][0][0]) <= 1001
    assert out["rows"][0][1] == 1
    assert out["rows"][0][2] == "[1, 2, 3]"


def test_sandbox_duplicate_column_names_and_describe_still_work(ws: Path) -> None:
    out = run_query(ws, "SELECT customer_id, customer_id FROM bronze.customers ORDER BY 1")
    assert [c["name"] for c in out["columns"]] == ["customer_id", "customer_id_1"]  # DuckDB de-duplicates names
    assert out["rows"][0] == [1, 1]
    assert run_query(ws, "SUMMARIZE bronze.customers")["row_count"] == 5


def test_sandbox_timestamp_with_time_zone_is_returned_as_text(ws: Path) -> None:
    out = run_query(ws, "SELECT TIMESTAMPTZ '2026-01-02 03:04:05+00' AS ts, now() AS n")
    assert out["row_count"] == 1
    assert out["rows"][0][0].startswith("2026-01-02")


def test_sandbox_process_memory_stays_bounded_for_huge_results(ws: Path) -> None:
    """The gate's probe used 6 GB; now values are cut in DuckDB and the process has an OS memory cap."""
    import subprocess
    request = ws / "req.json"
    response = ws / "res.json"
    limits = {**LIMITS, "memory_mb": 256, "process_memory_mb": 1024, "sql_max_rows": 1000, "sql_max_bytes": 1_500_000,
              "sql_timeout_s": 20}
    request.write_text(json.dumps({"op": "query", "allowed_root": str(ws), "limits": limits, "args": {
        "lakehouse_path": str(ws / "lakehouse.duckdb"), "sql": "SELECT repeat('x', 3000000) AS s FROM range(1001)"}}), encoding="utf-8")
    subprocess.run([sys.executable, "-I", str(WORKER / "runner.py"), str(request), str(response)], cwd=WORKER, timeout=120, check=True)
    body = json.loads(response.read_text(encoding="utf-8"))
    assert body["stats"]["peak_memory_mb"] < 1100, body["stats"]
    if body["ok"]:
        assert all(len(r[0]) <= 1001 for r in body["data"]["rows"])
    else:
        assert body["error_code"] in ("SQL_OUT_OF_MEMORY", "OUT_OF_MEMORY", "QUERY_TIMEOUT")


def test_sandbox_os_memory_cap_is_enforced() -> None:
    import subprocess
    code = (
        "import sys; sys.path.insert(0, r'%s')\n"
        "from ops.process_limits import apply_memory_cap\n"
        "assert apply_memory_cap(256)\n"
        "try:\n    b = bytearray(600 * 1024 * 1024)\n    print('ALLOCATED')\nexcept MemoryError:\n    print('CAPPED')\n" % WORKER
    )
    out = subprocess.run([sys.executable, "-I", "-c", code], capture_output=True, text=True, timeout=60)
    assert "CAPPED" in out.stdout, out.stdout + out.stderr


def test_transform_and_ingest_respect_lakehouse_size_and_columns(ws: Path) -> None:
    args = {"lakehouse_path": str(ws / "lakehouse.duckdb"), "preview_path": str(ws / "p.json")}
    with pytest.raises(RunnerError) as err:
        transform({**args, "layer": "silver", "table": "big",
                   "sql": "SELECT md5(range::varchar) || md5((range + 1)::varchar) AS a FROM range(200000)"},
                  {**LIMITS, "lakehouse_max_mb": 1, "max_rows": 1_000_000}, str(ws))
    assert err.value.code == "LAKEHOUSE_FULL"
    wide = ", ".join(f"{i} AS c{i}" for i in range(12))
    with pytest.raises(RunnerError) as err:
        transform({**args, "layer": "silver", "table": "wide", "sql": f"SELECT {wide}"}, {**LIMITS, "max_columns": 10}, str(ws))
    assert err.value.code == "TOO_MANY_COLUMNS"
    con = duckdb.connect(str(ws / "lakehouse.duckdb"), read_only=True)
    try:
        assert con.execute("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'silver'").fetchone()[0] == 0
    finally:
        con.close()
