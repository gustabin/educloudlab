"""JSON and Parquet ingestion (M7): same caps and sandbox as CSV, friendly errors for broken files.
(run: worker\\.venv\\Scripts\\python -m pytest worker/tests -q)"""
from __future__ import annotations

import json
import sys
from pathlib import Path

import duckdb
import pytest

WORKER = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WORKER))

from ops.common import RunnerError  # noqa: E402
from ops.csv_ops import ingest, profile  # noqa: E402

LIMITS = {"threads": 1, "memory_mb": 256, "max_rows": 1000, "max_columns": 10, "preview_rows": 5, "lakehouse_max_mb": 50}


@pytest.fixture()
def root(tmp_path: Path) -> Path:
    (tmp_path / "raw").mkdir()
    return tmp_path


def write(root: Path, name: str, content: str | bytes) -> Path:
    path = root / "raw" / name
    path.write_bytes(content if isinstance(content, bytes) else content.encode("utf-8"))
    return path


def run_profile(root: Path, path: Path, fmt: str) -> dict:
    return profile({"file_path": str(path), "format": fmt, "preview_path": str(root / "p.json")}, LIMITS, str(root))


def run_ingest(root: Path, path: Path, fmt: str, table: str = "t") -> dict:
    return ingest({"file_path": str(path), "format": fmt, "lakehouse_path": str(root / "lake.duckdb"),
                   "layer": "bronze", "table": table, "preview_path": str(root / "b.json")}, LIMITS, str(root))


def make_parquet(root: Path, name: str, select_sql: str) -> Path:
    path = root / "raw" / name
    con = duckdb.connect()
    con.execute(f"COPY ({select_sql}) TO '{path.as_posix()}' (FORMAT parquet)")
    con.close()
    return path


def test_json_array_and_ndjson_are_profiled_and_ingested(root: Path) -> None:
    array = write(root, "a.json", json.dumps([{"Customer ID": 1, "name": "Ana", "tags": ["x"], "addr": {"city": "Lima"}},
                                              {"Customer ID": 2, "name": "Luis", "tags": [], "addr": {"city": "Quito"}}]))
    out = run_profile(root, array, "json")
    assert out["format"] == "json" and out["row_count"] == 2
    assert [c["suggested_name"] for c in out["columns"]] == ["customer_id", "name", "tags", "addr"]

    ndjson = write(root, "b.jsonl", '{"id": 1, "amount": 10.5}\n{"id": 2, "amount": 7}\n')
    ingested = run_ingest(root, ndjson, "json", "orders")
    assert ingested["row_count"] == 2
    assert [c["name"] for c in ingested["columns"]] == ["id", "amount"]
    con = duckdb.connect(str(root / "lake.duckdb"), read_only=True)
    assert con.execute("SELECT sum(amount) FROM bronze.orders").fetchone()[0] == 17.5
    con.close()


def test_parquet_is_profiled_and_ingested(root: Path) -> None:
    path = make_parquet(root, "p.parquet", "SELECT range AS id, 'n' || range AS \"Full Name\" FROM range(20)")
    assert run_profile(root, path, "parquet")["row_count"] == 20
    out = run_ingest(root, path, "parquet", "people")
    assert [c["name"] for c in out["columns"]] == ["id", "full_name"]
    assert out["row_count"] == 20


@pytest.mark.parametrize("name,content,fmt,code", [
    ("bad.json", "[{\"a\": 1}, {\"a\": ", "json", "JSON_PARSE_ERROR"),
    ("bad2.json", "{\"a\": 1} garbage", "json", "JSON_PARSE_ERROR"),
    ("fake.parquet", b"PAR1" + b"\x00garbage" * 100 + b"PAR1", "parquet", "PARQUET_ERROR"),
], ids=["truncated-json", "trailing-garbage", "fake-parquet"])
def test_broken_files_fail_with_safe_messages(root: Path, name: str, content: str | bytes, fmt: str, code: str) -> None:
    path = write(root, name, content)
    with pytest.raises(RunnerError) as err:
        run_profile(root, path, fmt)
    assert err.value.code == code
    assert str(root) not in err.value.safe_message


def test_deep_nesting_is_bounded_not_expanded(root: Path) -> None:
    # Beyond maximum_depth DuckDB keeps the value as JSON text instead of building ever deeper STRUCT types.
    deep = write(root, "deep.json", "[" + '{"a":' * 15 + "1" + "}" * 15 + "]")
    out = run_profile(root, deep, "json")
    assert out["row_count"] == 1
    assert "JSON" in out["columns"][0]["type"]


def test_caps_apply_to_every_format(root: Path) -> None:
    wide = make_parquet(root, "wide.parquet", "SELECT " + ", ".join(f"{i} AS c{i}" for i in range(11)))
    with pytest.raises(RunnerError) as err:
        run_profile(root, wide, "parquet")
    assert err.value.code == "TOO_MANY_COLUMNS"
    long = write(root, "long.jsonl", "".join(json.dumps({"i": i}) + "\n" for i in range(1001)))
    with pytest.raises(RunnerError) as err:
        run_profile(root, long, "json")
    assert err.value.code == "TOO_MANY_ROWS"


def test_unknown_format_and_paths_outside_the_root_are_refused(root: Path, tmp_path_factory) -> None:
    path = write(root, "a.json", "[]")
    with pytest.raises(RunnerError) as err:
        run_profile(root, path, "xlsx")
    assert err.value.code == "BAD_REQUEST"
    outside = tmp_path_factory.mktemp("other") / "x.parquet"
    outside.write_bytes(b"PAR1PAR1")
    with pytest.raises(RunnerError) as err:
        profile({"file_path": str(outside), "format": "parquet", "preview_path": str(root / "p.json")}, LIMITS, str(root))
    assert err.value.code == "BAD_REQUEST"
