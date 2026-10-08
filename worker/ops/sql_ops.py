"""SQL Lab: student SQL (untrusted) against the workspace lakehouse.

Defence layers (M5, verified by worker/tests/test_sql_sandbox.py):
  1. statement allowlist: exactly one statement and its type must be SELECT (duckdb.extract_statements);
  2. denylist of functions/keywords that expose server internals (paths, settings, files, extensions);
  3. connection: read-only for queries; DuckDB sandbox with NO file access (allowed_directories = []),
     extensions not installable/loadable, configuration locked;
  4. limits: wall-clock interrupt, max rows, max result bytes; the dispatcher kills the process as a backstop;
  5. output: values containing the server path are masked; error messages are path-free.
Transforms embed the validated SELECT in CREATE OR REPLACE TABLE <silver|gold>.<table> AS ... and re-check
that the final text is a single CREATE statement.
"""
from __future__ import annotations

import json
import re
import threading
import time
from pathlib import Path
from typing import Any

import duckdb

from .common import (
    connect,
    parser as sql_parser,
    RunnerError,
    configure,
    confined,
    enforce_lakehouse_size,
    identifier,
    json_value,
    quote_ident,
    safe_error_message,
)

TRANSFORM_LAYERS = ("silver", "gold")

# Functions / keywords that would reveal server paths, settings or files, touch extensions, or execute SQL held in
# a string (query(), query_table(), json_execute_serialized_sql() would bypass the statement check). Matched as
# whole words anywhere in the text - deliberately also inside string literals and quoted identifiers, so SQL
# smuggled in a string is caught too.
DENYLIST = re.compile(
    r"\b(duckdb_\w+|current_setting|pragma\w*|getenv|read_\w+|glob|sniff_csv|parquet_\w+|"
    r"which_secret|load_extension|install|attach|detach|checkpoint|export|import|copy|pg_settings|"
    r"query|query_table|json_execute_serialized_sql|json_serialize_sql|sql_auto_complete)\b",
    re.IGNORECASE,
)

# Second, structural check on the parse tree (immune to quoting, comments and string tricks): function names and
# table references, independent of how they are spelled in the text.
DENIED_FUNCTION = re.compile(
    r"^(duckdb_.*|pragma_.*|read_.*|parquet_.*|current_setting|getenv|glob|sniff_csv|query|query_table|"
    r"json_execute_serialized_sql|json_serialize_sql|which_secret|load_extension|sql_auto_complete|.*_settings?|"
    r"enable_.*|disable_.*|.*checkpoint|truncate_duckdb_logs)$"
)
# Table functions (FROM f(...)) are allowlisted: DuckDB keeps adding table functions with side effects
# (enable_profiling(save_location := ...) writes files, checkpoint, logging), so only pure generators are accepted.
ALLOWED_TABLE_FUNCTIONS = {"range", "generate_series", "unnest", "json_each", "json_tree"}
DENIED_SCHEMAS = {"pg_catalog", "system"}
SAFE_TABLE_NAME = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")


def _check_parse_tree(sql: str) -> None:
    parser = sql_parser()
    try:
        raw = parser.execute("SELECT json_serialize_sql(?)", [sql]).fetchone()[0]
    finally:
        parser.close()
    tree = json.loads(raw)
    if tree.get("error"):
        raise RunnerError("SQL_FORBIDDEN", "Esta instrucción no está permitida en el SQL Lab.")

    def walk(node: Any) -> None:
        if isinstance(node, dict):
            name = node.get("function_name")
            if isinstance(name, str) and DENIED_FUNCTION.match(name.lower()):
                raise RunnerError("SQL_FORBIDDEN", f"La función «{name}» no está permitida en el SQL Lab.")
            if node.get("type") == "TABLE_FUNCTION":
                fn = str((node.get("function") or {}).get("function_name", "")).lower()
                if fn not in ALLOWED_TABLE_FUNCTIONS:
                    raise RunnerError("SQL_FORBIDDEN", f"La función de tabla «{fn}» no está permitida en el SQL Lab.")
            if node.get("type") == "BASE_TABLE":
                table = str(node.get("table_name", ""))
                schema = str(node.get("schema_name", "")).lower()
                catalog = str(node.get("catalog_name", "")).lower()
                if not SAFE_TABLE_NAME.match(table):
                    raise RunnerError("SQL_FORBIDDEN", "Solo puedes consultar tablas del lakehouse (no archivos ni URLs).")
                if schema in DENIED_SCHEMAS or catalog in DENIED_SCHEMAS or DENIED_FUNCTION.match(table.lower()):
                    raise RunnerError("SQL_FORBIDDEN", f"La tabla «{table}» no está permitida en el SQL Lab.")
            for value in node.values():
                walk(value)
        elif isinstance(node, list):
            for value in node:
                walk(value)

    walk(tree)


def read_tables(sql: str) -> list[str]:
    """Lakehouse tables (layer.table) referenced by a validated SELECT, from its parse tree (lineage)."""
    parser = sql_parser()
    try:
        tree = json.loads(parser.execute("SELECT json_serialize_sql(?)", [sql]).fetchone()[0])
    finally:
        parser.close()
    found: set[str] = set()

    def walk(node: Any) -> None:
        if isinstance(node, dict):
            if node.get("type") == "BASE_TABLE" and node.get("schema_name") in ("bronze", "silver", "gold"):
                found.add(f"{node['schema_name']}.{node.get('table_name')}")
            for value in node.values():
                walk(value)
        elif isinstance(node, list):
            for value in node:
                walk(value)

    walk(tree)
    return sorted(found)


def validate_select(sql: Any, max_length: int) -> str:
    if not isinstance(sql, str) or not sql.strip():
        raise RunnerError("SQL_EMPTY", "Escribe una consulta SQL.")
    if len(sql) > max_length:
        raise RunnerError("SQL_TOO_LONG", f"La consulta supera los {max_length} caracteres.")
    try:
        statements = duckdb.extract_statements(sql)
    except duckdb.Error as exc:
        raise RunnerError("SQL_SYNTAX_ERROR", "Error de sintaxis: " + safe_error_message(exc)) from exc
    if len(statements) != 1:
        raise RunnerError("SQL_FORBIDDEN", "Ejecuta una sola sentencia a la vez.")
    if statements[0].type != duckdb.StatementType.SELECT:
        raise RunnerError("SQL_FORBIDDEN", "En el SQL Lab solo se permiten consultas SELECT (incluye WITH, FROM, VALUES).")
    match = DENYLIST.search(sql)
    if match:
        raise RunnerError("SQL_FORBIDDEN", f"La función o instrucción «{match.group(1)}» no está permitida en el SQL Lab.")
    _check_parse_tree(sql)
    return sql.strip().rstrip(";").strip()


class _Interrupter:
    """Interrupts the connection after ``seconds`` (graceful timeout before the dispatcher's hard kill)."""

    def __init__(self, con: duckdb.DuckDBPyConnection, seconds: float) -> None:
        self.fired = False
        self._timer = threading.Timer(seconds, self._fire, args=(con,))

    def _fire(self, con: duckdb.DuckDBPyConnection) -> None:
        self.fired = True
        con.interrupt()

    def __enter__(self) -> "_Interrupter":
        self._timer.start()
        return self

    def __exit__(self, *exc: object) -> None:
        self._timer.cancel()


def _sql_error(exc: duckdb.Error, timeout_s: float, interrupted: bool) -> RunnerError:
    if interrupted or isinstance(exc, duckdb.InterruptException):
        return RunnerError("QUERY_TIMEOUT", f"La consulta superó el tiempo máximo de {timeout_s:g} s y se canceló.")
    kinds = {
        duckdb.ParserException: ("SQL_SYNTAX_ERROR", "Error de sintaxis"),
        duckdb.CatalogException: ("SQL_CATALOG_ERROR", "Tabla o columna desconocida"),
        duckdb.BinderException: ("SQL_BINDER_ERROR", "Referencia no válida"),
        duckdb.PermissionException: ("SQL_FORBIDDEN", "Operación no permitida"),
        duckdb.ConversionException: ("SQL_CONVERSION_ERROR", "Conversión de tipos no válida"),
        duckdb.OutOfMemoryException: ("SQL_OUT_OF_MEMORY", "La consulta necesita demasiada memoria"),
    }
    for cls, (code, label) in kinds.items():
        if isinstance(exc, cls):
            return RunnerError(code, f"{label}: {safe_error_message(exc)}")
    return RunnerError("SQL_ERROR", "Error al ejecutar la consulta: " + safe_error_message(exc))


def _mask(value: Any, secrets: list[str]) -> Any:
    if isinstance(value, str):
        lowered = value.lower()
        if any(s and s in lowered for s in secrets):
            return "<ruta oculta>"
    return value


def _open(lakehouse: Path, read_only: bool) -> duckdb.DuckDBPyConnection:
    if not lakehouse.exists():
        raise RunnerError("NO_TABLES", "El lakehouse de este workspace aún no tiene tablas. Ingiere un dataset primero.")
    return connect(str(lakehouse), read_only=read_only)


def fetch_bounded(con: duckdb.DuckDBPyConnection, sql: str, limits: dict[str, Any], max_rows: int) -> tuple[list[dict[str, str]], list[tuple[Any, ...]], bool]:
    """Runs a validated SELECT with every value bounded INSIDE DuckDB, before anything reaches Python.

    Positional aliases avoid duplicate-name problems; non-fixed-width types are cast to text and cut to
    ``sql_max_cell_chars``. Rows are fetched in batches against ``sql_max_bytes``. Returns raw Python values.
    """
    max_bytes = int(limits.get("sql_max_bytes", 1_500_000))
    max_cell = int(limits.get("sql_max_cell_chars", 1000))
    max_columns = int(limits.get("sql_max_columns", 200))
    described = con.execute(f"DESCRIBE SELECT * FROM ({sql})").fetchall()
    if len(described) > max_columns:
        raise RunnerError("TOO_MANY_COLUMNS", f"La consulta devuelve {len(described)} columnas; el máximo es {max_columns}.")
    columns = [{"name": str(d[0]), "type": str(d[1])} for d in described]
    aliases = [f"c{i}" for i in range(len(described))]
    projection = ", ".join(
        alias if _is_fixed_width(str(d[1])) else f"left(CAST({alias} AS VARCHAR), {max_cell})"
        for alias, d in zip(aliases, described)
    )
    cursor = con.execute(f"SELECT {projection} FROM ({sql}) AS _q({', '.join(aliases)}) LIMIT {max_rows + 1}")
    rows: list[tuple[Any, ...]] = []
    size = 0
    truncated = False
    while not truncated:
        batch = cursor.fetchmany(100)
        if not batch:
            break
        for raw in batch:
            if len(rows) >= max_rows:
                truncated = True
                break
            size += len(json.dumps(raw, ensure_ascii=False, default=str))
            if size > max_bytes:
                truncated = True
                break
            rows.append(raw)
    return columns, rows, truncated


def query(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    lakehouse = confined(args.get("lakehouse_path", ""), allowed_root, must_exist=False)
    sql = validate_select(args.get("sql"), int(limits.get("sql_max_length", 20000)))
    max_rows = int(limits.get("sql_max_rows", 1000))
    timeout_s = float(limits.get("sql_timeout_s", 10))
    max_cell = int(limits.get("sql_max_cell_chars", 1000))
    secrets = [allowed_root.lower(), allowed_root.replace("\\", "/").lower(), allowed_root.replace("/", "\\").lower()]

    con = _open(lakehouse, read_only=True)
    try:
        configure(con, limits, allowed_root, file_access=False)
        started = time.perf_counter()
        with _Interrupter(con, timeout_s) as guard:
            try:
                columns, raw_rows, truncated = fetch_bounded(con, sql, limits, max_rows)
            except duckdb.Error as exc:
                raise _sql_error(exc, timeout_s, guard.fired) from exc
        rows = [[_mask(json_value(v, max_text=max_cell), secrets) for v in raw] for raw in raw_rows]
        elapsed = int((time.perf_counter() - started) * 1000)
        return {"columns": columns, "rows": rows, "row_count": len(rows), "truncated": truncated, "elapsed_ms": elapsed}
    finally:
        con.close()


_FIXED_WIDTH = re.compile(
    r"^(BOOLEAN|TINYINT|SMALLINT|INTEGER|BIGINT|HUGEINT|UTINYINT|USMALLINT|UINTEGER|UBIGINT|UHUGEINT|FLOAT|DOUBLE|"
    r"DECIMAL\(\d+,\d+\)|DATE|TIME|TIMESTAMP|TIMESTAMP_S|TIMESTAMP_MS|TIMESTAMP_NS|"
    r"INTERVAL|UUID)$"
)
# TIMESTAMP WITH TIME ZONE is deliberately absent: fetching it natively needs pytz, so it is cast to VARCHAR in DuckDB.


def _is_fixed_width(type_name: str) -> bool:
    return bool(_FIXED_WIDTH.match(type_name.upper()))


def transform(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    lakehouse = confined(args.get("lakehouse_path", ""), allowed_root, must_exist=False)
    preview_path = confined(args.get("preview_path", ""), allowed_root, must_exist=False)
    layer = args.get("layer")
    if layer not in TRANSFORM_LAYERS:
        raise RunnerError("BAD_REQUEST", "Las transformaciones crean tablas en silver o gold.")
    table = identifier(args.get("table"))
    select = validate_select(args.get("sql"), int(limits.get("sql_max_length", 20000)))
    statement = f"CREATE OR REPLACE TABLE {layer}.{quote_ident(table)} AS {select}"
    parsed = duckdb.extract_statements(statement)
    if len(parsed) != 1 or parsed[0].type != duckdb.StatementType.CREATE:
        raise RunnerError("SQL_FORBIDDEN", "La consulta no puede usarse como transformación.")
    timeout_s = float(limits.get("transform_timeout_s", 60))

    con = _open(lakehouse, read_only=False)
    try:
        for schema in ("bronze", "silver", "gold"):
            con.execute(f"CREATE SCHEMA IF NOT EXISTS {schema}")
        configure(con, limits, allowed_root, file_access=False)
        with _Interrupter(con, timeout_s) as guard:
            try:
                con.execute(statement)
            except duckdb.Error as exc:
                raise _sql_error(exc, timeout_s, guard.fired) from exc
        target = f"{layer}.{quote_ident(table)}"
        row_count = int(con.execute(f"SELECT count(*) FROM {target}").fetchone()[0])
        schema_rows = con.execute(
            "SELECT column_name, data_type FROM information_schema.columns "
            "WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position",
            [layer, table],
        ).fetchall()
        max_rows = int(limits.get("max_rows", 500_000))
        max_columns = int(limits.get("max_columns", 100))
        problem = None
        if row_count > max_rows:
            problem = ("TOO_MANY_ROWS", f"La tabla resultante tiene {row_count} filas; el máximo es {max_rows}.")
        elif len(schema_rows) > max_columns:
            problem = ("TOO_MANY_COLUMNS", f"La tabla resultante tiene {len(schema_rows)} columnas; el máximo es {max_columns}.")
        else:
            problem = enforce_lakehouse_size(con, lakehouse, limits)
        if problem is not None:
            con.execute(f"DROP TABLE {target}")
            con.execute("CHECKPOINT")
            raise RunnerError(*problem)
        preview = con.execute(f"SELECT * FROM {target} LIMIT {int(limits.get('preview_rows', 50))}").fetchall()
        payload = {"columns": [r[0] for r in schema_rows], "rows": [[json_value(v) for v in row] for row in preview]}
        preview_path.parent.mkdir(parents=True, exist_ok=True)
        tmp = preview_path.with_suffix(".tmp")
        tmp.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
        tmp.replace(preview_path)
        return {
            "layer": layer,
            "table": table,
            "row_count": row_count,
            "columns": [{"name": n, "type": t} for n, t in schema_rows],
            "sources": read_tables(select),
        }
    finally:
        con.close()
