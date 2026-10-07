"""Pipeline engine (op "pipeline"): correct results, per-step report, quality gates, and no SQL smuggling.
(run: worker\\.venv\\Scripts\\python -m pytest worker/tests -q)"""
from __future__ import annotations

import sys
from pathlib import Path

import duckdb
import pytest

WORKER = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WORKER))

from ops.common import RunnerError  # noqa: E402
from ops.pipeline_ops import PipelineError, pipeline  # noqa: E402

LIMITS = {"threads": 1, "memory_mb": 256, "max_rows": 10_000, "max_columns": 20, "preview_rows": 5, "sql_max_length": 5000,
          "transform_timeout_s": 5, "lakehouse_max_mb": 50}


@pytest.fixture()
def ws(tmp_path: Path) -> Path:
    con = duckdb.connect(str(tmp_path / "lakehouse.duckdb"))
    con.execute("CREATE SCHEMA bronze; CREATE SCHEMA silver; CREATE SCHEMA gold")
    con.execute("""CREATE TABLE bronze.orders AS SELECT * FROM (VALUES
        (1, 10, 'completed', 100.0), (2, 10, 'cancelled', 50.0), (3, 20, 'completed', 30.0), (4, 30, 'completed', 20.0)
    ) t(order_id, store_id, status, total)""")
    con.execute("CREATE TABLE bronze.stores AS SELECT * FROM (VALUES (10, 'Norte'), (20, 'Sur'), (30, 'Sur')) t(store_id, region)")
    con.close()
    (tmp_path / "raw").mkdir()
    return tmp_path


def run(ws: Path, nodes: list[dict], raw_inputs: dict | None = None) -> dict:
    return pipeline({"lakehouse_path": str(ws / "lakehouse.duckdb"), "preview_path": str(ws / "p.json"),
                     "nodes": nodes, "raw_inputs": raw_inputs or {}}, LIMITS, str(ws))


def table(ws: Path, sql: str) -> list[tuple]:
    con = duckdb.connect(str(ws / "lakehouse.duckdb"), read_only=True)
    try:
        return con.execute(sql).fetchall()
    finally:
        con.close()


REVENUE = [
    {"id": "leer", "type": "source", "table": "bronze.orders"},
    {"id": "completados", "type": "filter", "conditions": [{"column": "status", "op": "eq", "value": "completed"}]},
    {"id": "con_region", "type": "join", "table": "bronze.stores", "on": [{"left": "store_id", "right": "store_id"}], "columns": ["region"]},
    {"id": "por_region", "type": "aggregate", "group_by": ["region"],
     "measures": [{"fn": "count", "column": "order_id", "as": "pedidos"}, {"fn": "sum", "column": "total", "as": "ingresos"}]},
    {"id": "ticket", "type": "sql", "sql": "SELECT region, pedidos, ingresos, round(ingresos / pedidos, 2) AS ticket FROM input"},
    {"id": "calidad", "type": "quality_check", "rules": [{"rule": "not_null", "column": "region"}, {"rule": "unique", "columns": ["region"]},
                                                          {"rule": "range", "column": "ingresos", "min": 0}, {"rule": "row_count", "min": 1}]},
    {"id": "guardar", "type": "output", "layer": "gold", "table": "ventas_region"},
]


def test_end_to_end_pipeline_writes_the_output_and_reports_every_step(ws: Path) -> None:
    out = run(ws, REVENUE)
    assert out["status"] == "succeeded"
    assert [s["status"] for s in out["steps"]] == ["succeeded"] * 7
    assert [s["rows"] for s in out["steps"]] == [4, 3, 3, 2, 2, 2, 2]
    assert out["sources"] == ["bronze.orders", "bronze.stores"]
    assert out["output"]["row_count"] == 2
    assert sorted(table(ws, "SELECT region, pedidos, ingresos, ticket FROM gold.ventas_region")) == [("Norte", 1, 100.0, 100.0), ("Sur", 2, 50.0, 25.0)]
    # Re-running replaces the output (idempotent).
    assert run(ws, REVENUE)["output"]["row_count"] == 2


def test_select_transforms_and_casts(ws: Path) -> None:
    out = run(ws, [
        {"id": "s", "type": "source", "table": "bronze.orders"},
        {"id": "cols", "type": "select", "columns": [{"column": "order_id", "as": "id"}, {"column": "status", "transform": "upper"},
                                                       {"column": "total", "cast": "integer", "as": "total_entero"}]},
        {"id": "o", "type": "output", "layer": "silver", "table": "pedidos"},
    ])
    assert [c["name"] for c in out["output"]["columns"]] == ["id", "status", "total_entero"]
    assert table(ws, "SELECT status, total_entero FROM silver.pedidos WHERE id = 1") == [("COMPLETED", 100)]


def test_quality_failure_stops_or_warns(ws: Path) -> None:
    strict = [REVENUE[0], {"id": "q", "type": "quality_check", "rules": [{"rule": "accepted_values", "column": "status", "values": ["completed"]}]},
              {"id": "o", "type": "output", "layer": "silver", "table": "x"}]
    with pytest.raises(PipelineError) as err:
        run(ws, strict)
    assert err.value.code == "QUALITY_FAILED"
    assert "1 valores de status no están permitidos" in err.value.safe_message
    assert [s["status"] for s in err.value.data["steps"]] == ["succeeded", "failed", "skipped"]
    assert table(ws, "SELECT count(*) FROM information_schema.tables WHERE table_name = 'x'") == [(0,)]

    strict[1]["on_fail"] = "warn"
    out = run(ws, strict)
    assert [s["status"] for s in out["steps"]] == ["succeeded", "warning", "succeeded"]


def test_raw_csv_source_is_read_from_the_workspace(ws: Path) -> None:
    csv = ws / "raw" / "k1"
    csv.write_text("Customer ID,email\n1,a@x.com\n2,\n", encoding="utf-8")
    out = run(ws, [
        {"id": "raw", "type": "source", "raw": "clientes"},
        {"id": "f", "type": "filter", "conditions": [{"column": "email", "op": "not_null"}]},
        {"id": "o", "type": "output", "layer": "silver", "table": "clientes"},
    ], raw_inputs={"clientes": {"path": str(csv), "format": "csv"}})
    assert out["raw_sources"] == ["clientes"]
    assert out["output"]["row_count"] == 1


@pytest.mark.parametrize("sql", [
    "SELECT * FROM enable_profiling(format := 'json', save_location := '{target}') UNION ALL BY NAME SELECT * FROM input",
    "SELECT * FROM enable_logging() UNION ALL BY NAME SELECT * FROM input",
    "SELECT * FROM force_checkpoint() UNION ALL BY NAME SELECT * FROM input",
    "SELECT * FROM read_text('{raw}')",
    "SELECT * FROM '{target}'",
], ids=["enable-profiling-write", "enable-logging", "force-checkpoint", "read-text-raw", "replacement-scan"])
def test_sql_node_cannot_touch_files_even_with_raw_inputs(ws: Path, sql: str) -> None:
    """Security gate M7-01: with a raw source the connection may read exactly the raw files, nothing else."""
    raw = ws / "raw" / "k1"
    raw.write_text("id,email\n1,a@x.com\n", encoding="utf-8")
    target = ws / "meta" / "victim.preview.json"
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text('{"ok": true}', encoding="utf-8")
    with pytest.raises(PipelineError) as err:
        run(ws, [
            {"id": "s", "type": "source", "raw": "clientes"},
            {"id": "x", "type": "sql", "sql": sql.format(target=str(target).replace("\\", "/"), raw=str(raw).replace("\\", "/"))},
            {"id": "o", "type": "output", "layer": "silver", "table": "y"},
        ], raw_inputs={"clientes": {"path": str(raw), "format": "csv"}})
    assert err.value.data["steps"][1]["status"] == "failed"
    assert target.read_text(encoding="utf-8") == '{"ok": true}'
    assert str(ws) not in err.value.safe_message


@pytest.mark.parametrize("node", [
    {"id": "x", "type": "filter", "conditions": [{"column": 'status" OR 1=1 --', "op": "eq", "value": 1}]},
    {"id": "x", "type": "filter", "conditions": [{"column": "status", "op": "; DROP TABLE bronze.orders", "value": 1}]},
    {"id": "x", "type": "select", "columns": [{"column": "total", "transform": "pg_read_file"}]},
    {"id": "x", "type": "select", "columns": [{"column": "total", "cast": "VARCHAR); DROP TABLE x; --"}]},
    {"id": "x", "type": "join", "table": "main.secret", "on": [{"left": "a", "right": "b"}], "columns": ["c"]},
    {"id": "x", "type": "aggregate", "measures": [{"fn": "string_agg", "column": "status", "as": "s"}]},
    {"id": "x", "type": "sql", "sql": "SELECT * FROM read_csv('C:/Windows/win.ini')"},
    {"id": "x", "type": "sql", "sql": "SELECT 1; DROP TABLE bronze.orders"},
    {"id": "x", "type": "sql", "sql": "COPY bronze.orders TO 'x.csv'"},
    {"id": "x", "type": "shell", "cmd": "whoami"},
], ids=["column-injection", "operator-injection", "unknown-function", "cast-injection", "foreign-table",
        "unknown-aggregate", "sql-file-read", "sql-multi-statement", "sql-copy", "unknown-node"])
def test_smuggling_attempts_fail_the_step_without_side_effects(ws: Path, node: dict) -> None:
    with pytest.raises(PipelineError) as err:
        run(ws, [{"id": "s", "type": "source", "table": "bronze.orders"}, node,
                 {"id": "o", "type": "output", "layer": "silver", "table": "y"}])
    assert err.value.data["steps"][1]["status"] == "failed"
    assert table(ws, "SELECT count(*) FROM bronze.orders") == [(4,)]
    assert str(ws) not in err.value.safe_message


def test_filter_values_are_bound_not_interpolated(ws: Path) -> None:
    out = run(ws, [
        {"id": "s", "type": "source", "table": "bronze.orders"},
        {"id": "f", "type": "filter", "conditions": [{"column": "status", "op": "eq", "value": "x' OR '1'='1"}]},
        {"id": "o", "type": "output", "layer": "silver", "table": "z"},
    ])
    assert out["output"]["row_count"] == 0


def test_structure_rules(ws: Path) -> None:
    with pytest.raises(RunnerError):
        run(ws, [{"id": "o", "type": "output", "layer": "silver", "table": "y"}])
    with pytest.raises(RunnerError):
        run(ws, [{"id": "s", "type": "source", "table": "bronze.orders"}, {"id": "f", "type": "filter", "conditions": []}])
    with pytest.raises(PipelineError) as err:
        run(ws, [{"id": "s", "type": "source", "table": "bronze.orders"}, {"id": "o", "type": "output", "layer": "bronze", "table": "y"}])
    assert err.value.code == "BAD_PIPELINE"
    with pytest.raises(PipelineError) as err:
        run(ws, [{"id": "s", "type": "source", "table": "bronze.missing"}, {"id": "o", "type": "output", "layer": "silver", "table": "y"}])
    assert err.value.code == "SQL_CATALOG_ERROR"


def test_row_caps_apply_per_step(ws: Path) -> None:
    with pytest.raises(PipelineError) as err:
        pipeline({"lakehouse_path": str(ws / "lakehouse.duckdb"), "preview_path": str(ws / "p.json"), "nodes": [
            {"id": "s", "type": "source", "table": "bronze.orders"},
            {"id": "x", "type": "sql", "sql": "SELECT * FROM input, range(5000)"},
            {"id": "o", "type": "output", "layer": "silver", "table": "big"},
        ]}, LIMITS, str(ws))
    assert err.value.code == "TOO_MANY_ROWS"
