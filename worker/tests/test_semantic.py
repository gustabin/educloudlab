"""Semantic layer (op "semantic"): compiled queries return the right numbers, and no SQL can be smuggled in.
(run: worker\\.venv\\Scripts\\python -m pytest worker/tests -q)"""
from __future__ import annotations

import copy
import sys
from pathlib import Path

import duckdb
import pytest

WORKER = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WORKER))

from ops.common import RunnerError  # noqa: E402
from ops.semantic_ops import semantic  # noqa: E402

LIMITS = {"threads": 1, "memory_mb": 256, "semantic_max_rows": 100, "sql_timeout_s": 5, "sql_max_bytes": 200_000}

MODEL = {
    "fact": "gold.fact_sales",
    "relationships": [
        {"table": "gold.dim_store", "fact_column": "store_key", "column": "store_key"},
        {"table": "gold.dim_date", "fact_column": "date_key", "column": "date_key"},
    ],
    "measures": [
        {"name": "ingresos", "agg": "sum", "column": "amount"},
        {"name": "pedidos", "agg": "count_distinct", "column": "order_id"},
        {"name": "lineas", "agg": "count"},
        {"name": "ticket_medio", "ratio": ["ingresos", "pedidos"]},
    ],
    "dimensions": [
        {"name": "region", "table": "gold.dim_store", "column": "region"},
        {"name": "mes", "table": "gold.dim_date", "column": "full_date", "grain": "month"},
        {"name": "canal", "column": "channel"},
    ],
}


@pytest.fixture()
def ws(tmp_path: Path) -> Path:
    con = duckdb.connect(str(tmp_path / "lakehouse.duckdb"))
    con.execute("CREATE SCHEMA bronze; CREATE SCHEMA silver; CREATE SCHEMA gold")
    con.execute("CREATE TABLE gold.dim_store AS SELECT * FROM (VALUES (1, 'Norte'), (2, 'Sur')) t(store_key, region)")
    con.execute("""CREATE TABLE gold.dim_date AS SELECT * FROM (VALUES
        (20250105, DATE '2025-01-05'), (20250210, DATE '2025-02-10')) t(date_key, full_date)""")
    con.execute("""CREATE TABLE gold.fact_sales AS SELECT * FROM (VALUES
        (1, 1, 20250105, 'web', 100.0), (1, 1, 20250105, 'web', 50.0), (2, 2, 20250105, 'tienda', 30.0),
        (3, 2, 20250210, 'web', 20.0), (4, 99, 20250210, 'tienda', 10.0)
    ) t(order_id, store_key, date_key, channel, amount)""")
    con.execute("CREATE TABLE bronze.secret AS SELECT 'x' AS s")
    con.close()
    return tmp_path


def run(ws: Path, queries: list[dict], model: dict | None = None) -> dict:
    return semantic({"lakehouse_path": str(ws / "lakehouse.duckdb"), "model": model or MODEL, "queries": queries},
                    LIMITS, str(ws))


def test_measures_by_dimension_join_only_what_is_needed(ws: Path) -> None:
    out = run(ws, [{"key": "w1", "measures": ["ingresos", "pedidos", "ticket_medio"], "dimensions": ["region"]}])
    res = out["results"]["w1"]
    assert [c["name"] for c in res["columns"]] == ["region", "ingresos", "pedidos", "ticket_medio"]
    assert [c["role"] for c in res["columns"]] == ["dimension", "measure", "measure", "measure"]
    # LEFT JOIN keeps the fact row with an unknown store (region NULL); ORDER BY puts NULL last.
    assert res["rows"] == [["Norte", 150.0, 1, 150.0], ["Sur", 50.0, 2, 25.0], [None, 10.0, 1, 10.0]]


def test_kpi_date_grain_filters_and_order(ws: Path) -> None:
    out = run(ws, [
        {"key": "kpi", "measures": ["ingresos", "lineas"]},
        {"key": "by_month", "measures": ["ingresos"], "dimensions": ["mes"], "order": {"by": "mes", "dir": "desc"}},
        {"key": "web_norte", "measures": ["ingresos"], "filters": [
            {"dimension": "canal", "op": "eq", "value": "web"}, {"dimension": "region", "op": "in", "value": ["Norte"]}]},
        {"key": "rango", "measures": ["pedidos"], "filters": [
            {"dimension": "mes", "op": "between", "value": ["2025-02-01", "2025-02-28"]}]},
        {"key": "valores", "dimensions": ["canal"]},
    ])["results"]
    assert out["kpi"]["rows"] == [[210.0, 5]]
    assert out["by_month"]["rows"] == [["2025-02-01", 30.0], ["2025-01-01", 180.0]]
    assert out["by_month"]["columns"][0]["role"] == "date"
    assert out["web_norte"]["rows"] == [[150.0]]
    assert out["rango"]["rows"] == [[2]]
    assert out["valores"]["rows"] == [["tienda"], ["web"]]


def test_filter_values_are_bound_not_interpolated(ws: Path) -> None:
    out = run(ws, [{"key": "q", "measures": ["lineas"], "filters": [
        {"dimension": "canal", "op": "eq", "value": "web' OR '1'='1"}]}])
    assert out["results"]["q"]["rows"] == [[0]]


def test_limit_truncates(ws: Path) -> None:
    res = run(ws, [{"key": "q", "measures": ["ingresos"], "dimensions": ["canal"], "limit": 1}])["results"]["q"]
    assert len(res["rows"]) == 1 and res["truncated"] is True


def test_a_failing_query_does_not_fail_the_others(ws: Path) -> None:
    model = copy.deepcopy(MODEL)
    model["dimensions"].append({"name": "fantasma", "column": "no_existe"})
    out = run(ws, [{"key": "ok", "measures": ["lineas"]}, {"key": "mal", "measures": ["lineas"], "dimensions": ["fantasma"]}], model)
    assert out["results"]["ok"]["rows"] == [[5]]
    assert out["results"]["mal"]["error"]["code"] == "SQL_BINDER_ERROR"
    assert str(ws) not in out["results"]["mal"]["error"]["message"]


@pytest.mark.parametrize("patch", [
    {"fact": "bronze.secret"},
    {"fact": "gold.fact_sales; DROP TABLE gold.dim_store"},
    {"fact": "main.fact_sales"},
    {"measures": [{"name": "x", "agg": "string_agg", "column": "channel"}]},
    {"measures": [{"name": "x", "agg": "sum", "column": "amount) FROM gold.fact_sales; --"}]},
    {"measures": [{"name": "x; DROP", "agg": "sum", "column": "amount"}]},
    {"measures": [{"name": "x", "ratio": ["x", "x"]}]},
    {"measures": [{"name": "a", "agg": "sum", "column": "amount"}, {"name": "r1", "ratio": ["a", "a"]},
                  {"name": "r2", "ratio": ["r1", "r1"]}]},
    {"relationships": [{"table": "read_csv('C:/Windows/win.ini')", "fact_column": "a", "column": "b"}]},
    {"relationships": [{"table": "gold.dim_store", "fact_column": "store_key = 1 OR 1", "column": "store_key"}]},
    {"dimensions": [{"name": "d", "column": "channel", "grain": "century'); DROP TABLE x; --"}]},
    {"dimensions": [{"name": "d", "table": "bronze.secret", "column": "s"}]},
], ids=["bronze-fact", "fact-injection", "other-schema", "unknown-agg", "column-injection", "name-injection",
        "self-ratio", "nested-ratio", "table-function", "join-injection", "grain-injection", "unrelated-table"])
def test_malicious_models_are_rejected(ws: Path, patch: dict) -> None:
    model = {**copy.deepcopy(MODEL), **patch}
    with pytest.raises(RunnerError) as err:
        run(ws, [{"key": "q", "measures": ["lineas"]}], model)
    assert err.value.code == "BAD_MODEL"
    con = duckdb.connect(str(ws / "lakehouse.duckdb"), read_only=True)
    assert con.execute("SELECT count(*) FROM gold.dim_store").fetchone() == (2,)
    con.close()


@pytest.mark.parametrize("request_", [
    {"measures": ["ingresos; DROP TABLE gold.dim_store"]},
    {"measures": ["ingresos"], "dimensions": ["region", "region"]},
    {"measures": ["ingresos"], "filters": [{"dimension": "canal", "op": "like", "value": "%"}]},
    {"measures": ["ingresos"], "filters": [{"dimension": "canal", "op": "in", "value": "web"}]},
    {"measures": ["ingresos"], "filters": [{"dimension": "amount", "op": "eq", "value": 1}]},
    {"measures": ["ingresos"], "order": {"by": "1; DROP TABLE x"}},
    {"measures": ["ingresos"], "limit": 100000},
    {"measures": ["ingresos"], "limit": "10; DROP"},
    {},
], ids=["measure-injection", "dup-dimension", "unknown-op", "in-needs-list", "unknown-filter-dimension",
        "order-injection", "limit-too-big", "limit-string", "empty"])
def test_malicious_requests_fail_only_their_query(ws: Path, request_: dict) -> None:
    out = run(ws, [{"key": "q", **request_}, {"key": "ok", "measures": ["lineas"]}])["results"]
    assert out["q"]["error"]["code"] == "BAD_MODEL"
    assert out["ok"]["rows"] == [[5]]


def test_no_file_access_and_read_only(ws: Path) -> None:
    model = copy.deepcopy(MODEL)
    model["measures"].append({"name": "x", "agg": "sum", "column": "amount"})
    # The connection is read-only and sandboxed: even a valid model cannot write or read files.
    out = run(ws, [{"key": "q", "measures": ["x"]}], model)
    assert out["results"]["q"]["rows"] == [[210.0]]
    con = duckdb.connect(str(ws / "lakehouse.duckdb"), read_only=True)
    assert con.execute("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'gold'").fetchone() == (3,)
    con.close()


def test_job_shape_is_validated(ws: Path) -> None:
    with pytest.raises(RunnerError):
        run(ws, [])
    with pytest.raises(RunnerError):
        run(ws, [{"key": "Bad Key!", "measures": ["lineas"]}])
    with pytest.raises(RunnerError):
        run(ws, [{"key": "a", "measures": ["lineas"]}, {"key": "a", "measures": ["lineas"]}])
