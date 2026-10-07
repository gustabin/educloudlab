"""Lab Engine data checks (M6, ADR-008): op "validate".

Inspects the ACTUAL state of a lab workspace's lakehouse; nothing the client claims is trusted. The check list is
built by the dispatcher from the imported lab definition (trusted platform content) plus, for exercise tasks, the
student's saved SQL answer (untrusted). Every SQL text - student answers and author SQL alike - goes through the
SQL Lab sandbox: validate_select(), read-only connection, no file access, locked configuration, per-statement
interrupt and bounded fetch. Structural checks build SQL only from validated identifiers.

Each check yields {id, passed, feedback, evidence}; one failing or broken check never aborts the others.
"""
from __future__ import annotations

import datetime
import decimal
import math
import re
from pathlib import Path
from typing import Any, Callable

import duckdb

from .common import RunnerError, configure, confined, identifier, quote_ident
from .sql_ops import _Interrupter, _sql_error, fetch_bounded, validate_select

TABLE = re.compile(r"^(bronze|silver|gold)\.([a-z][a-z0-9_]{0,62})$")
TYPE_NAME = re.compile(r"^[A-Z][A-Z0-9_ ]{1,30}$")
COMPARISONS = ("eq", "gte", "lte", "between")
MAX_CHECKS = 200


class _Ctx:
    def __init__(self, con: duckdb.DuckDBPyConnection, limits: dict[str, Any]) -> None:
        self.con = con
        self.limits = limits
        self.timeout_s = float(limits.get("sql_timeout_s", 10))
        self.compare_rows = int(limits.get("lab_compare_max_rows", 1000))

    def scalar(self, sql: str, params: list[Any] | None = None) -> Any:
        with _Interrupter(self.con, self.timeout_s) as guard:
            try:
                row = self.con.execute(sql, params or []).fetchone()
            except duckdb.Error as exc:
                raise _sql_error(exc, self.timeout_s, guard.fired) from exc
        return None if row is None else row[0]

    def columns(self, table: str) -> tuple[str, list[tuple[str, str]]]:
        """Returns (qualified quoted name, [(column, type)]); fails with a friendly message if the table is missing."""
        match = TABLE.match(table if isinstance(table, str) else "")
        if not match:
            raise RunnerError("BAD_CHECK", "Comprobación mal definida (tabla).")
        layer, name = match.group(1), match.group(2)
        rows = self.con.execute(
            "SELECT column_name, data_type FROM information_schema.columns "
            "WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position",
            [layer, name],
        ).fetchall()
        if not rows:
            raise RunnerError("TABLE_MISSING", f"La tabla {table} no existe todavía.")
        return f"{layer}.{quote_ident(name)}", [(str(r[0]), str(r[1])) for r in rows]

    def column(self, table: str, column: Any) -> tuple[str, str, str]:
        qualified, cols = self.columns(table)
        name = identifier(column, "columna")
        types = {c: t for c, t in cols}
        if name not in types:
            raise RunnerError("COLUMN_MISSING", f"La tabla {table} no tiene la columna {name}.")
        return qualified, quote_ident(name), types[name]

    def fetch(self, sql: str) -> tuple[int, list[tuple[Any, ...]], bool]:
        with _Interrupter(self.con, self.timeout_s) as guard:
            try:
                columns, rows, truncated = fetch_bounded(self.con, sql, self.limits, self.compare_rows)
            except duckdb.Error as exc:
                raise _sql_error(exc, self.timeout_s, guard.fired) from exc
        return len(columns), rows, truncated


def _compare(value: int, op: Any, expected: Any) -> bool:
    if op == "between":
        if not isinstance(expected, list) or len(expected) != 2:
            raise RunnerError("BAD_CHECK", "Comprobación mal definida (rango).")
        return int(expected[0]) <= value <= int(expected[1])
    if op not in COMPARISONS or isinstance(expected, list):
        raise RunnerError("BAD_CHECK", "Comprobación mal definida (operador).")
    target = int(expected)
    return value == target if op == "eq" else value >= target if op == "gte" else value <= target


def _describe_expectation(op: str, expected: Any) -> str:
    return {
        "eq": f"exactamente {expected}",
        "gte": f"al menos {expected}",
        "lte": f"como máximo {expected}",
    }.get(op, f"entre {expected[0]} y {expected[1]}" if isinstance(expected, list) else str(expected))


# ----------------------------------------------------------------------------------------------- structural checks
def _table_has_columns(ctx: _Ctx, check: dict[str, Any]) -> tuple[bool, str, dict[str, Any]]:
    _, cols = ctx.columns(check.get("table"))
    present = {c for c, _ in cols}
    wanted = [identifier(c, "columna") for c in check.get("columns") or []]
    missing = [c for c in wanted if c not in present]
    if missing:
        return False, f"A la tabla {check['table']} le faltan columnas: {', '.join(missing)}.", {"missing": missing}
    return True, "", {}


def _column_type(ctx: _Ctx, check: dict[str, Any]) -> tuple[bool, str, dict[str, Any]]:
    _, _, actual = ctx.column(check.get("table"), check.get("column"))
    allowed = [t for t in check.get("types") or [] if isinstance(t, str) and TYPE_NAME.match(t)]
    base = actual.upper().split("(")[0].strip()
    if base in allowed or actual.upper() in allowed:
        return True, "", {"type": actual}
    return False, f"La columna {check['column']} es de tipo {actual}; se esperaba {' o '.join(allowed)}.", {"type": actual}


def _row_count(ctx: _Ctx, check: dict[str, Any]) -> tuple[bool, str, dict[str, Any]]:
    qualified, _ = ctx.columns(check.get("table"))
    n = int(ctx.scalar(f"SELECT count(*) FROM {qualified}"))
    if _compare(n, check.get("op"), check.get("value")):
        return True, "", {"rows": n}
    expectation = _describe_expectation(str(check.get("op")), check.get("value"))
    return False, f"La tabla {check['table']} tiene {n} filas; se esperaban {expectation}.", {"rows": n}


def _null_count(ctx: _Ctx, check: dict[str, Any]) -> tuple[bool, str, dict[str, Any]]:
    qualified, column, _ = ctx.column(check.get("table"), check.get("column"))
    n = int(ctx.scalar(f"SELECT count(*) FROM {qualified} WHERE {column} IS NULL"))
    if _compare(n, check.get("op"), check.get("value")):
        return True, "", {"nulls": n}
    expectation = _describe_expectation(str(check.get("op")), check.get("value"))
    return False, f"La columna {check['column']} tiene {n} valores nulos; se esperaban {expectation}.", {"nulls": n}


def _unique(ctx: _Ctx, check: dict[str, Any]) -> tuple[bool, str, dict[str, Any]]:
    qualified, cols = ctx.columns(check.get("table"))
    present = {c for c, _ in cols}
    wanted = [identifier(c, "columna") for c in check.get("columns") or []]
    missing = [c for c in wanted if c not in present]
    if not wanted or missing:
        return False, f"A la tabla {check['table']} le faltan columnas: {', '.join(missing)}.", {"missing": missing}
    keys = ", ".join(quote_ident(c) for c in wanted)
    groups = int(ctx.scalar(f"SELECT count(*) FROM (SELECT {keys} FROM {qualified} GROUP BY {keys} HAVING count(*) > 1)"))
    if groups == 0:
        return True, "", {}
    return False, f"Hay {groups} valores repetidos de ({', '.join(wanted)}) en {check['table']}.", {"duplicate_groups": groups}


def _references(ctx: _Ctx, check: dict[str, Any]) -> tuple[bool, str, dict[str, Any]]:
    """Referential integrity (M9, warehouse): every non-null key of ``table`` exists in ``ref_table``."""
    qualified, cols = ctx.columns(check.get("table"))
    ref_qualified, ref_cols = ctx.columns(check.get("ref_table"))
    wanted = [identifier(c, "columna") for c in check.get("columns") or []]
    ref_wanted = [identifier(c, "columna") for c in check.get("ref_columns") or []]
    if not wanted or len(wanted) != len(ref_wanted) or len(wanted) > 5:
        raise RunnerError("BAD_CHECK", "Comprobación mal definida (references).")
    missing = [c for c in wanted if c not in {n for n, _ in cols}]
    ref_missing = [c for c in ref_wanted if c not in {n for n, _ in ref_cols}]
    if missing:
        return False, f"A la tabla {check['table']} le faltan columnas: {', '.join(missing)}.", {"missing": missing}
    if ref_missing:
        return False, f"A la tabla {check['ref_table']} le faltan columnas: {', '.join(ref_missing)}.", {"missing": ref_missing}
    on = " AND ".join(f"t.{quote_ident(a)} = r.{quote_ident(b)}" for a, b in zip(wanted, ref_wanted))
    not_null = " AND ".join(f"t.{quote_ident(a)} IS NOT NULL" for a in wanted)
    orphans = int(ctx.scalar(
        f"SELECT count(*) FROM {qualified} t WHERE {not_null} AND NOT EXISTS (SELECT 1 FROM {ref_qualified} r WHERE {on})"
    ))
    if orphans == 0:
        return True, "", {}
    return False, (f"Hay {orphans} filas de {check['table']} cuya clave ({', '.join(wanted)}) no existe en "
                   f"{check['ref_table']}."), {"orphans": orphans}


def _value_range(ctx: _Ctx, check: dict[str, Any]) -> tuple[bool, str, dict[str, Any]]:
    qualified, column, _ = ctx.column(check.get("table"), check.get("column"))
    low, high = check.get("min"), check.get("max")
    if (low is not None and not isinstance(low, (int, float))) or (high is not None and not isinstance(high, (int, float))):
        raise RunnerError("BAD_CHECK", "Comprobación mal definida (rango).")
    conditions = []
    if low is not None:
        conditions.append(f"TRY_CAST({column} AS DOUBLE) < {float(low)!r}")
    if high is not None:
        conditions.append(f"TRY_CAST({column} AS DOUBLE) > {float(high)!r}")
    if not conditions:
        raise RunnerError("BAD_CHECK", "Comprobación mal definida (rango).")
    outside = int(ctx.scalar(f"SELECT count(*) FROM {qualified} WHERE {' OR '.join(conditions)}"))
    if outside == 0:
        return True, "", {}
    bounds = " y ".join(([f"≥ {low}"] if low is not None else []) + ([f"≤ {high}"] if high is not None else []))
    return False, f"{outside} valores de {check['column']} están fuera del rango permitido ({bounds}).", {"outside": outside}


# ----------------------------------------------------------------------------------------------- result comparison
def _normalise(value: Any, decimals: int) -> Any:
    if value is None or isinstance(value, bool):
        return value
    if isinstance(value, (int, float, decimal.Decimal)):
        number = float(value)
        if math.isnan(number) or math.isinf(number):
            return str(number)
        return round(number, decimals) + 0.0
    if isinstance(value, datetime.datetime):
        if value.tzinfo is None and value.time() == datetime.time(0, 0):
            return value.date().isoformat()
        return value.isoformat()
    if isinstance(value, (datetime.date, datetime.time)):
        return value.isoformat()
    return str(value)


def _sort_key(row: tuple[Any, ...]) -> tuple[Any, ...]:
    return tuple((v is None, type(v).__name__, v if v is not None else 0) for v in row)


def _same(a: Any, b: Any, tolerance: float) -> bool:
    if isinstance(a, float) and isinstance(b, float):
        return abs(a - b) <= tolerance
    return a == b


def _query_result_matches(ctx: _Ctx, check: dict[str, Any]) -> tuple[bool, str, dict[str, Any]]:
    max_length = int(ctx.limits.get("sql_max_length", 20000))
    decimals = int(check.get("decimals", 2))
    if not 0 <= decimals <= 6:
        raise RunnerError("BAD_CHECK", "Comprobación mal definida (decimales).")
    from_answer = "actual_sql" not in check
    actual_sql = check.get("student_sql") if from_answer else check.get("actual_sql")
    if from_answer and (not isinstance(actual_sql, str) or not actual_sql.strip()):
        return False, "Guarda una respuesta SQL para este ejercicio antes de validar.", {"answered": False}

    try:
        expected_cols, expected_rows, expected_truncated = ctx.fetch(validate_select(check.get("expected_sql"), max_length))
    except RunnerError as exc:
        # Author SQL is confidential: never echo engine errors that could name its tables or columns.
        return False, "No se pudo calcular el resultado esperado. Avisa a tu instructor.", {"expected_error": exc.code}
    try:
        actual_cols, actual_rows, actual_truncated = ctx.fetch(validate_select(actual_sql, max_length))
    except RunnerError as exc:
        if from_answer:
            return False, "Tu consulta falló: " + exc.safe_message, {"error": exc.code}
        if exc.code == "QUERY_TIMEOUT":
            return False, exc.safe_message, {"author_error": exc.code}
        return False, "Tus tablas aún no tienen la forma esperada (faltan tablas o columnas, o los tipos no son los pedidos).", {"author_error": exc.code}

    if expected_truncated or actual_truncated:
        return False, "El resultado es demasiado grande para compararlo. Revisa los filtros o la agregación.", {"truncated": True}
    if actual_cols != expected_cols:
        return False, f"La consulta devuelve {actual_cols} columnas; se esperaban {expected_cols}.", {"columns": actual_cols}
    if len(actual_rows) != len(expected_rows):
        return False, f"La consulta devuelve {len(actual_rows)} filas; se esperaban {len(expected_rows)}.", {"rows": len(actual_rows)}

    actual = [tuple(_normalise(v, decimals) for v in r) for r in actual_rows]
    expected = [tuple(_normalise(v, decimals) for v in r) for r in expected_rows]
    if not check.get("ordered", False):
        actual.sort(key=_sort_key)
        expected.sort(key=_sort_key)
    tolerance = 0.5 * 10 ** -decimals + 1e-9
    for a_row, e_row in zip(actual, expected):
        if not all(_same(a, e, tolerance) for a, e in zip(a_row, e_row)):
            return False, "Los valores no coinciden con el resultado esperado.", {"rows": len(actual_rows)}
    return True, "", {"rows": len(actual_rows)}


CHECKS: dict[str, Callable[[_Ctx, dict[str, Any]], tuple[bool, str, dict[str, Any]]]] = {
    "table_has_columns": _table_has_columns,
    "column_type": _column_type,
    "row_count": _row_count,
    "null_count": _null_count,
    "unique": _unique,
    "references": _references,
    "value_range": _value_range,
    "query_result_matches": _query_result_matches,
}


def validate(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    lakehouse = confined(args.get("lakehouse_path", ""), allowed_root, must_exist=False)
    checks = args.get("checks")
    if not isinstance(checks, list) or len(checks) > MAX_CHECKS or not all(isinstance(c, dict) for c in checks):
        raise RunnerError("BAD_REQUEST", "Lista de comprobaciones no válida.")
    if not checks:
        return {"results": []}
    if not Path(lakehouse).exists():
        return {"results": [
            {"id": str(c.get("id", "")), "passed": False, "feedback": "El lakehouse del workspace aún no tiene tablas.", "evidence": {}}
            for c in checks
        ]}

    con = duckdb.connect(str(lakehouse), read_only=True)
    results = []
    try:
        configure(con, limits, allowed_root, file_access=False)
        ctx = _Ctx(con, limits)
        for check in checks:
            check_id = str(check.get("id", ""))
            handler = CHECKS.get(str(check.get("type")))
            try:
                if handler is None:
                    raise RunnerError("BAD_CHECK", "Tipo de comprobación desconocido.")
                passed, feedback, evidence = handler(ctx, check)
            except RunnerError as exc:
                passed, feedback, evidence = False, exc.safe_message, {"error": exc.code}
            except duckdb.Error as exc:
                error = _sql_error(exc, ctx.timeout_s, False)
                passed, feedback, evidence = False, error.safe_message, {"error": error.code}
            results.append({"id": check_id, "passed": bool(passed), "feedback": feedback[:300], "evidence": evidence})
    finally:
        con.close()
    return {"results": results}
