"""Lab Engine data checks (op "validate"): correct verdicts, sandboxed student SQL, robust to broken input.
(run: worker\\.venv\\Scripts\\python -m pytest worker/tests -q)"""
from __future__ import annotations

import sys
from pathlib import Path

import duckdb
import pytest

WORKER = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WORKER))

from ops.common import RunnerError  # noqa: E402
from ops.lab_ops import validate  # noqa: E402

LIMITS = {"threads": 1, "memory_mb": 256, "sql_max_rows": 100, "sql_max_bytes": 200_000, "sql_timeout_s": 2,
          "sql_max_length": 5000, "lab_compare_max_rows": 50}


@pytest.fixture()
def ws(tmp_path: Path) -> Path:
    con = duckdb.connect(str(tmp_path / "lakehouse.duckdb"))
    con.execute("CREATE SCHEMA bronze; CREATE SCHEMA silver; CREATE SCHEMA gold")
    con.execute("""CREATE TABLE bronze.customers AS SELECT * FROM (VALUES
        (1, 'ana@x.com', DATE '2025-01-03', 10.5), (2, NULL, DATE '2025-02-01', 7.25), (2, 'luis@x.com', DATE '2025-02-01', 7.25),
        (3, 'eva@x.com', DATE '2025-03-09', -1.0)
    ) t(customer_id, email, signup_date, amount)""")
    con.close()
    return tmp_path


def run(ws: Path, *checks: dict) -> list[dict]:
    payload = [dict(c, id=f"c{i}") for i, c in enumerate(checks)]
    out = validate({"lakehouse_path": str(ws / "lakehouse.duckdb"), "checks": payload}, LIMITS, str(ws))
    return out["results"]


def verdicts(ws: Path, *checks: dict) -> list[bool]:
    return [r["passed"] for r in run(ws, *checks)]


def test_structural_checks_pass_and_fail(ws: Path) -> None:
    t = "bronze.customers"
    assert verdicts(
        ws,
        {"type": "table_has_columns", "table": t, "columns": ["customer_id", "email"]},
        {"type": "table_has_columns", "table": t, "columns": ["customer_id", "phone"]},
        {"type": "column_type", "table": t, "column": "signup_date", "types": ["DATE"]},
        {"type": "column_type", "table": t, "column": "email", "types": ["DATE"]},
        {"type": "row_count", "table": t, "op": "eq", "value": 4},
        {"type": "row_count", "table": t, "op": "between", "value": [5, 9]},
        {"type": "null_count", "table": t, "column": "email", "op": "eq", "value": 1},
        {"type": "null_count", "table": t, "column": "email", "op": "eq", "value": 0},
        {"type": "unique", "table": t, "columns": ["email"]},
        {"type": "unique", "table": t, "columns": ["customer_id"]},
        {"type": "value_range", "table": t, "column": "amount", "min": -5, "max": 20},
        {"type": "value_range", "table": t, "column": "amount", "min": 0},
    ) == [True, False, True, False, True, False, True, False, True, False, True, False]


def test_feedback_is_specific_and_helpful(ws: Path) -> None:
    results = run(
        ws,
        {"type": "table_has_columns", "table": "silver.customers", "columns": ["email"]},
        {"type": "unique", "table": "bronze.customers", "columns": ["customer_id"]},
        {"type": "row_count", "table": "bronze.customers", "op": "gte", "value": 10},
        {"type": "null_count", "table": "bronze.customers", "column": "nope", "op": "eq", "value": 0},
    )
    assert results[0]["feedback"] == "La tabla silver.customers no existe todavía."
    assert "1 valores repetidos" in results[1]["feedback"]
    assert results[2]["feedback"] == "La tabla bronze.customers tiene 4 filas; se esperaban al menos 10."
    assert "no tiene la columna nope" in results[3]["feedback"]
    assert [r["id"] for r in results] == ["c0", "c1", "c2", "c3"]


def test_query_result_matches_ordered_unordered_and_decimals(ws: Path) -> None:
    expected = "SELECT customer_id, amount FROM bronze.customers WHERE email IS NOT NULL ORDER BY customer_id"
    assert verdicts(
        ws,
        {"type": "query_result_matches", "expected_sql": expected,
         "student_sql": "SELECT customer_id AS id, amount AS total FROM bronze.customers WHERE email IS NOT NULL ORDER BY 1 DESC"},
        {"type": "query_result_matches", "expected_sql": expected, "ordered": True,
         "student_sql": "SELECT customer_id, amount FROM bronze.customers WHERE email IS NOT NULL ORDER BY 1 DESC"},
        {"type": "query_result_matches", "expected_sql": "SELECT round(avg(amount), 2) FROM bronze.customers",
         "student_sql": "SELECT avg(amount) FROM bronze.customers", "decimals": 2},
        {"type": "query_result_matches", "expected_sql": "SELECT CAST(4.0 AS DECIMAL(10,2))", "student_sql": "SELECT 4"},
        {"type": "query_result_matches", "expected_sql": "SELECT DATE '2025-01-01'",
         "student_sql": "SELECT date_trunc('month', TIMESTAMP '2025-01-20 10:00:00')"},
    ) == [True, False, True, True, True]


def test_query_result_mismatch_explains_shape(ws: Path) -> None:
    expected = "SELECT customer_id, email FROM bronze.customers"
    results = run(
        ws,
        {"type": "query_result_matches", "expected_sql": expected, "student_sql": "SELECT customer_id FROM bronze.customers"},
        {"type": "query_result_matches", "expected_sql": expected, "student_sql": "SELECT customer_id, email FROM bronze.customers LIMIT 2"},
        {"type": "query_result_matches", "expected_sql": expected, "student_sql": "SELECT customer_id, upper(email) FROM bronze.customers"},
        {"type": "query_result_matches", "expected_sql": expected},
        {"type": "query_result_matches", "expected_sql": expected, "student_sql": "SELEC 1"},
    )
    assert not any(r["passed"] for r in results)
    assert results[0]["feedback"] == "La consulta devuelve 1 columnas; se esperaban 2."
    assert results[1]["feedback"] == "La consulta devuelve 2 filas; se esperaban 4."
    assert results[2]["feedback"] == "Los valores no coinciden con el resultado esperado."
    assert results[3]["feedback"].startswith("Guarda una respuesta SQL")
    assert results[4]["feedback"].startswith("Tu consulta falló: Error de sintaxis")


@pytest.mark.parametrize("student_sql", [
    "SELECT * FROM read_csv('C:/Windows/win.ini')",
    "SELECT current_setting('home_directory')",
    "COPY bronze.customers TO 'x.csv'",
    "DROP TABLE bronze.customers",
    "SELECT 1; DROP TABLE bronze.customers",
    "SELECT * FROM \"query\"('SELECT 1')",
    "ATTACH 'other.duckdb'",
])
def test_student_answers_are_sandboxed(ws: Path, student_sql: str) -> None:
    result = run(ws, {"type": "query_result_matches", "expected_sql": "SELECT 1", "student_sql": student_sql})[0]
    assert result["passed"] is False
    assert result["feedback"].startswith("Tu consulta falló")
    assert str(ws) not in result["feedback"]
    con = duckdb.connect(str(ws / "lakehouse.duckdb"), read_only=True)
    assert con.execute("SELECT count(*) FROM bronze.customers").fetchone()[0] == 4
    con.close()


def test_student_answer_timeouts_and_huge_results_fail_cleanly(ws: Path) -> None:
    results = run(
        ws,
        {"type": "query_result_matches", "expected_sql": "SELECT 1",
         "student_sql": "SELECT count(*) FROM range(100000000000) a"},
        {"type": "query_result_matches", "expected_sql": "SELECT * FROM range(10)",
         "student_sql": "SELECT * FROM range(100000)"},
    )
    assert "tiempo máximo" in results[0]["feedback"]
    assert "demasiado grande" in results[1]["feedback"]


def test_author_sql_errors_do_not_reveal_hidden_identifiers(ws: Path) -> None:
    results = run(
        ws,
        {"type": "query_result_matches", "expected_sql": "SELECT 0",
         "actual_sql": "SELECT secret_column FROM gold.hidden_table"},
        {"type": "query_result_matches", "expected_sql": "SELECT secret_column FROM gold.hidden_table",
         "student_sql": "SELECT 1"},
    )
    for result in results:
        assert result["passed"] is False
        assert "secret_column" not in result["feedback"] and "hidden_table" not in result["feedback"]
    assert results[0]["feedback"].startswith("Tus tablas aún no tienen la forma esperada")


@pytest.mark.parametrize("check", [
    {"type": "row_count", "table": "bronze.customers; DROP TABLE x", "op": "eq", "value": 1},
    {"type": "row_count", "table": "main.secret", "op": "eq", "value": 1},
    {"type": "null_count", "table": "bronze.customers", "column": "email\" IS NULL OR \"1", "op": "eq", "value": 0},
    {"type": "unique", "table": "bronze.customers", "columns": ["x) OR (1=1"]},
    {"type": "row_count", "table": "bronze.customers", "op": "ne", "value": 1},
    {"type": "value_range", "table": "bronze.customers", "column": "amount", "min": "0; DROP"},
    {"type": "shell", "table": "bronze.customers"},
])
def test_malformed_checks_fail_without_executing_anything(ws: Path, check: dict) -> None:
    result = run(ws, check)[0]
    assert result["passed"] is False


def test_missing_lakehouse_and_bad_payloads(tmp_path: Path) -> None:
    out = validate({"lakehouse_path": str(tmp_path / "lakehouse.duckdb"),
                    "checks": [{"id": "a", "type": "row_count", "table": "bronze.t", "op": "eq", "value": 1}]}, LIMITS, str(tmp_path))
    assert out["results"][0]["passed"] is False
    assert "aún no tiene tablas" in out["results"][0]["feedback"]
    with pytest.raises(RunnerError):
        validate({"lakehouse_path": str(tmp_path / "x.duckdb"), "checks": "nope"}, LIMITS, str(tmp_path))
    with pytest.raises(RunnerError):
        validate({"lakehouse_path": "C:/Windows/x.duckdb", "checks": []}, LIMITS, str(tmp_path))
