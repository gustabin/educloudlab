"""Pipeline engine (M7, ADR-009): op "pipeline".

A pipeline is a LINEAR chain of allowlisted nodes. Each node is compiled here into DuckDB SQL from a fixed template:
identifiers are validated (^[a-z_][a-z0-9_]*$) and quoted, operators and functions come from allowlists and every
literal value is a bound parameter. The only node accepting free SQL ("sql") goes through the SQL Lab sandbox
(validate_select: one SELECT, denylist, parse-tree check) and reads the previous step as the relation ``input``.

Execution: each step is materialised as a TEMP table (row count + caps per step, memory bounded by memory_limit,
no spill: temp_directory is neutralised), the final step is written to <silver|gold>.<table>. The connection is
configured with NO file access except the raw inputs the dispatcher resolved (read before the sandbox is locked is
not possible, so raw inputs are read with allowed_directories = [workspace dir]).

Result: {"status": "succeeded", "steps": [...], "output": {...}, "sources": [...]} or a PipelineError carrying the
steps executed so far (reported as partial data on failure).
"""
from __future__ import annotations

import json
import re
import time
from pathlib import Path
from typing import Any

import duckdb

from .common import (
    connect,
    RunnerError,
    configure,
    confined,
    enforce_lakehouse_size,
    identifier,
    json_value,
    quote_ident,
)
from .csv_ops import Source
from .sql_ops import _Interrupter, _sql_error, validate_select

COLUMN = re.compile(r"^[a-z_][a-z0-9_]{0,62}$")
TABLE = re.compile(r"^(bronze|silver|gold)\.([a-z][a-z0-9_]{0,62})$")
NODE_TYPES = ("source", "filter", "select", "join", "aggregate", "sql", "quality_check", "output")
FILTER_OPS = {"eq": "=", "ne": "<>", "gt": ">", "gte": ">=", "lt": "<", "lte": "<="}
AGGREGATES = {"sum": "sum({})", "count": "count({})", "avg": "avg({})", "min": "min({})", "max": "max({})",
              "count_distinct": "count(DISTINCT {})"}
TRANSFORMS = {"lower": "lower({})", "upper": "upper({})", "trim": "trim({})", "abs": "abs({})",
              "round": "round({}, 2)", "year": "year({})", "month": "month({})", "date": "CAST({} AS DATE)",
              "month_start": "date_trunc('month', {})"}
CASTS = {"varchar": "VARCHAR", "integer": "INTEGER", "bigint": "BIGINT", "double": "DOUBLE", "decimal": "DECIMAL(18,2)",
         "date": "DATE", "timestamp": "TIMESTAMP", "boolean": "BOOLEAN"}
MAX_NODES = 20


class PipelineError(RunnerError):
    """A failed run: carries the per-step report so the UI can show where it stopped."""

    def __init__(self, code: str, safe_message: str, steps: list[dict[str, Any]]) -> None:
        super().__init__(code, safe_message)
        self.data = {"status": "failed", "steps": steps}


def _col(value: Any) -> str:
    if not isinstance(value, str) or not COLUMN.match(value):
        raise RunnerError("BAD_PIPELINE", "Nombre de columna no válido en el pipeline.")
    return quote_ident(value)


def _table(value: Any) -> tuple[str, str, str]:
    match = TABLE.match(value if isinstance(value, str) else "")
    if not match:
        raise RunnerError("BAD_PIPELINE", "Tabla no válida en el pipeline (usa capa.tabla, por ejemplo bronze.orders).")
    return f"{match.group(1)}.{quote_ident(match.group(2))}", match.group(1), match.group(2)


def _value(value: Any) -> Any:
    if isinstance(value, (str, int, float, bool)) or value is None:
        if isinstance(value, str) and len(value) > 1000:
            raise RunnerError("BAD_PIPELINE", "Valor demasiado largo en el pipeline.")
        return value
    raise RunnerError("BAD_PIPELINE", "Valor no válido en el pipeline.")


# ----------------------------------------------------------------------------------------------- node compilers
def _filter(node: dict[str, Any], prev: str) -> tuple[str, list[Any]]:
    conditions, params = [], []
    for c in node.get("conditions") or []:
        col, op = _col(c.get("column")), c.get("op")
        if op in FILTER_OPS:
            conditions.append(f"{col} {FILTER_OPS[op]} ?")
            params.append(_value(c.get("value")))
        elif op in ("in", "not_in"):
            values = c.get("value")
            if not isinstance(values, list) or not 1 <= len(values) <= 100:
                raise RunnerError("BAD_PIPELINE", "El operador in necesita una lista de 1 a 100 valores.")
            conditions.append(f"{col} {'NOT ' if op == 'not_in' else ''}IN ({', '.join('?' for _ in values)})")
            params.extend(_value(v) for v in values)
        elif op in ("is_null", "not_null"):
            conditions.append(f"{col} IS {'NOT ' if op == 'not_null' else ''}NULL")
        elif op in ("contains", "starts_with"):
            conditions.append(f"{'contains' if op == 'contains' else 'starts_with'}(CAST({col} AS VARCHAR), ?)")
            params.append(str(_value(c.get("value"))))
        else:
            raise RunnerError("BAD_PIPELINE", "Operador de filtro no permitido.")
    if not conditions:
        raise RunnerError("BAD_PIPELINE", "El filtro necesita al menos una condición.")
    joiner = " OR " if node.get("match") == "any" else " AND "
    return f"SELECT * FROM {prev} WHERE " + joiner.join(f"({c})" for c in conditions), params


def _select(node: dict[str, Any], prev: str) -> tuple[str, list[Any]]:
    parts = []
    for c in node.get("columns") or []:
        expr = _col(c.get("column"))
        if c.get("transform") is not None:
            if c["transform"] not in TRANSFORMS:
                raise RunnerError("BAD_PIPELINE", "Transformación de columna no permitida.")
            expr = TRANSFORMS[c["transform"]].format(expr)
        if c.get("cast") is not None:
            if c["cast"] not in CASTS:
                raise RunnerError("BAD_PIPELINE", "Tipo de conversión no permitido.")
            expr = f"TRY_CAST({expr} AS {CASTS[c['cast']]})"
        alias = _col(c.get("as") or c.get("column"))
        parts.append(f"{expr} AS {alias}")
    if not parts:
        raise RunnerError("BAD_PIPELINE", "El nodo select necesita al menos una columna.")
    return f"SELECT {', '.join(parts)} FROM {prev}", []


def _join(node: dict[str, Any], prev: str, sources: set[str]) -> tuple[str, list[Any]]:
    table, layer, name = _table(node.get("table"))
    sources.add(f"{layer}.{name}")
    pairs = node.get("on") or []
    if not pairs:
        raise RunnerError("BAD_PIPELINE", "El join necesita al menos una pareja de columnas en on.")
    on = " AND ".join(f"l.{_col(p.get('left'))} = r.{_col(p.get('right'))}" for p in pairs)
    kind = {"inner": "JOIN", "left": "LEFT JOIN"}.get(node.get("kind", "inner"))
    if kind is None:
        raise RunnerError("BAD_PIPELINE", "Tipo de join no permitido (inner o left).")
    extra = [f"r.{_col(c)} AS {_col(c)}" for c in node.get("columns") or []]
    if not extra:
        raise RunnerError("BAD_PIPELINE", "Indica qué columnas de la tabla unida quieres conservar (columns).")
    return f"SELECT l.*, {', '.join(extra)} FROM {prev} l {kind} {table} r ON {on}", []


def _aggregate(node: dict[str, Any], prev: str) -> tuple[str, list[Any]]:
    groups = [_col(g) for g in node.get("group_by") or []]
    measures = []
    for m in node.get("measures") or []:
        if m.get("fn") not in AGGREGATES:
            raise RunnerError("BAD_PIPELINE", "Función de agregación no permitida.")
        column = "*" if m.get("fn") == "count" and m.get("column") in (None, "*") else _col(m.get("column"))
        measures.append(f"{AGGREGATES[m['fn']].format(column)} AS {_col(m.get('as'))}")
    if not measures:
        raise RunnerError("BAD_PIPELINE", "El nodo aggregate necesita al menos una medida.")
    group_sql = f" GROUP BY {', '.join(groups)}" if groups else ""
    return f"SELECT {', '.join(groups + measures)} FROM {prev}{group_sql}", []


def _sql(node: dict[str, Any], prev: str, limits: dict[str, Any], con: duckdb.DuckDBPyConnection, sources: set[str]) -> tuple[str, list[Any]]:
    select = validate_select(node.get("sql"), int(limits.get("sql_max_length", 20000)))
    tree = json.loads(con.execute("SELECT json_serialize_sql(?)", [select]).fetchone()[0])

    def walk(n: Any) -> None:
        if isinstance(n, dict):
            if n.get("type") == "BASE_TABLE" and n.get("schema_name") in ("bronze", "silver", "gold"):
                sources.add(f"{n['schema_name']}.{n.get('table_name')}")
            for v in n.values():
                walk(v)
        elif isinstance(n, list):
            for v in n:
                walk(v)

    walk(tree)
    return f"WITH input AS (SELECT * FROM {prev}) {select}", []


def _quality(node: dict[str, Any], prev: str, con: duckdb.DuckDBPyConnection, ctx: _Run) -> list[str]:
    failures = []
    for rule in node.get("rules") or []:
        kind = rule.get("rule")
        if kind == "not_null":
            n = ctx.scalar(f"SELECT count(*) FROM {prev} WHERE {_col(rule.get('column'))} IS NULL")
            if n:
                failures.append(f"{rule['column']} tiene {n} valores nulos")
        elif kind == "unique":
            cols = ", ".join(_col(c) for c in rule.get("columns") or [])
            if not cols:
                raise RunnerError("BAD_PIPELINE", "La regla unique necesita columnas.")
            n = ctx.scalar(f"SELECT count(*) FROM (SELECT {cols} FROM {prev} GROUP BY {cols} HAVING count(*) > 1)")
            if n:
                failures.append(f"{n} valores repetidos de ({', '.join(rule['columns'])})")
        elif kind == "row_count":
            n = ctx.scalar(f"SELECT count(*) FROM {prev}")
            low, high = rule.get("min"), rule.get("max")
            if (isinstance(low, int) and n < low) or (isinstance(high, int) and n > high):
                failures.append(f"el resultado tiene {n} filas (esperado entre {low if low is not None else '-'} y {high if high is not None else '-'})")
        elif kind == "range":
            col = _col(rule.get("column"))
            conds, params = [], []
            for key, op in (("min", "<"), ("max", ">")):
                if rule.get(key) is not None:
                    conds.append(f"TRY_CAST({col} AS DOUBLE) {op} ?")
                    params.append(float(_value(rule[key])))
            if not conds:
                raise RunnerError("BAD_PIPELINE", "La regla range necesita min o max.")
            n = ctx.scalar(f"SELECT count(*) FROM {prev} WHERE {' OR '.join(conds)}", params)
            if n:
                failures.append(f"{n} valores de {rule['column']} fuera de rango")
        elif kind == "accepted_values":
            values = rule.get("values")
            if not isinstance(values, list) or not 1 <= len(values) <= 100:
                raise RunnerError("BAD_PIPELINE", "accepted_values necesita una lista de 1 a 100 valores.")
            n = ctx.scalar(
                f"SELECT count(*) FROM {prev} WHERE {_col(rule.get('column'))} NOT IN ({', '.join('?' for _ in values)})",
                [_value(v) for v in values],
            )
            if n:
                failures.append(f"{n} valores de {rule['column']} no están permitidos")
        else:
            raise RunnerError("BAD_PIPELINE", "Regla de calidad no permitida.")
    return failures


class _Run:
    def __init__(self, con: duckdb.DuckDBPyConnection, limits: dict[str, Any]) -> None:
        self.con = con
        self.limits = limits
        self.timeout_s = float(limits.get("transform_timeout_s", 60))

    def execute(self, sql: str, params: list[Any] | None = None) -> None:
        with _Interrupter(self.con, self.timeout_s) as guard:
            try:
                self.con.execute(sql, params or [])
            except duckdb.Error as exc:
                raise _sql_error(exc, self.timeout_s, guard.fired) from exc

    def scalar(self, sql: str, params: list[Any] | None = None) -> int:
        with _Interrupter(self.con, self.timeout_s) as guard:
            try:
                return int(self.con.execute(sql, params or []).fetchone()[0])
            except duckdb.Error as exc:
                raise _sql_error(exc, self.timeout_s, guard.fired) from exc


def pipeline(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    lakehouse = confined(args.get("lakehouse_path", ""), allowed_root, must_exist=False)
    preview_path = confined(args.get("preview_path", ""), allowed_root, must_exist=False)
    nodes = args.get("nodes")
    if not isinstance(nodes, list) or not 2 <= len(nodes) <= MAX_NODES or not all(isinstance(n, dict) for n in nodes):
        raise RunnerError("BAD_PIPELINE", f"Un pipeline tiene entre 2 y {MAX_NODES} nodos.")
    if nodes[0].get("type") != "source" or nodes[-1].get("type") != "output":
        raise RunnerError("BAD_PIPELINE", "El pipeline empieza con un nodo source y termina con un nodo output.")
    raw_inputs = args.get("raw_inputs") or {}
    max_rows = int(limits.get("max_rows", 500_000))
    max_columns = int(limits.get("max_columns", 100))

    lakehouse.parent.mkdir(parents=True, exist_ok=True)
    con = connect(str(lakehouse))
    steps: list[dict[str, Any]] = []
    sources: set[str] = set()
    raw_sources: set[str] = set()
    try:
        for schema in ("bronze", "silver", "gold"):
            con.execute(f"CREATE SCHEMA IF NOT EXISTS {schema}")
        # No directory access at all: only the exact raw input files may be read (student SQL in "sql" nodes runs on
        # this connection, so nothing below the workspace may be written); everything else as in the SQL Lab sandbox.
        raw_files = [str(confined(info.get("path", ""), allowed_root)) for info in raw_inputs.values() if isinstance(info, dict)]
        configure(con, limits, allowed_root, file_access=False, allowed_files=raw_files)
        run = _Run(con, limits)
        prev = ""
        for i, node in enumerate(nodes):
            kind = node.get("type")
            step = {"id": str(node.get("id", f"n{i}"))[:40], "type": str(kind), "status": "running", "rows": None, "duration_ms": None, "message": None}
            steps.append(step)
            started = time.perf_counter()
            try:
                if kind not in NODE_TYPES or (kind in ("source", "output") and i not in (0, len(nodes) - 1)):
                    raise RunnerError("BAD_PIPELINE", "Tipo de nodo no permitido en esta posición.")
                if kind == "output":
                    layer = node.get("layer")
                    if layer not in ("silver", "gold"):
                        raise RunnerError("BAD_PIPELINE", "La salida se escribe en silver o gold.")
                    table = identifier(node.get("table"))
                    target = f"{layer}.{quote_ident(table)}"
                    run.execute(f"CREATE OR REPLACE TABLE {target} AS SELECT * FROM {prev}")
                    rows = run.scalar(f"SELECT count(*) FROM {target}")
                    problem = enforce_lakehouse_size(con, lakehouse, limits)
                    if problem is not None:
                        con.execute(f"DROP TABLE {target}")
                        con.execute("CHECKPOINT")
                        raise RunnerError(*problem)
                    step.update(status="succeeded", rows=rows)
                    continue
                if kind == "source":
                    if "raw" in node:
                        info = raw_inputs.get(node.get("raw")) if isinstance(node.get("raw"), str) else None
                        if not isinstance(info, dict):
                            raise RunnerError("BAD_PIPELINE", "El dataset raw indicado no existe en el workspace.")
                        src = Source(confined(info.get("path", ""), allowed_root), str(info.get("format", "csv")), limits)
                        raw_sources.add(str(node["raw"]))
                        sql, params = src.sql(), src.params
                    else:
                        table, layer, name = _table(node.get("table"))
                        sources.add(f"{layer}.{name}")
                        sql, params = f"SELECT * FROM {table}", []
                elif kind == "filter":
                    sql, params = _filter(node, prev)
                elif kind == "select":
                    sql, params = _select(node, prev)
                elif kind == "join":
                    sql, params = _join(node, prev, sources)
                elif kind == "aggregate":
                    sql, params = _aggregate(node, prev)
                elif kind == "sql":
                    sql, params = _sql(node, prev, limits, con, sources)
                else:  # quality_check: inspects the previous step, passes it through unchanged
                    failures = _quality(node, prev, con, run)
                    if failures:
                        message = "Calidad: " + "; ".join(failures)[:250]
                        if node.get("on_fail", "stop") == "warn":
                            step.update(status="warning", message=message, rows=run.scalar(f"SELECT count(*) FROM {prev}"))
                            continue
                        raise RunnerError("QUALITY_FAILED", message)
                    step.update(status="succeeded", rows=run.scalar(f"SELECT count(*) FROM {prev}"))
                    continue
                name = f"_step_{i}"
                run.execute(f"CREATE TEMP TABLE {name} AS {sql}", params)
                rows = run.scalar(f"SELECT count(*) FROM {name}")
                columns = len(con.execute(f"DESCRIBE {name}").fetchall())
                if rows > max_rows:
                    raise RunnerError("TOO_MANY_ROWS", f"El paso produce {rows} filas; el máximo es {max_rows}.")
                if columns > max_columns:
                    raise RunnerError("TOO_MANY_COLUMNS", f"El paso produce {columns} columnas; el máximo es {max_columns}.")
                step.update(status="succeeded", rows=rows)
                prev = name
            except RunnerError as exc:
                step.update(status="failed", message=exc.safe_message[:300])
                for later in nodes[i + 1:]:
                    steps.append({"id": str(later.get("id", ""))[:40], "type": str(later.get("type")), "status": "skipped",
                                  "rows": None, "duration_ms": None, "message": None})
                raise PipelineError(exc.code, exc.safe_message, steps) from exc
            finally:
                step["duration_ms"] = int((time.perf_counter() - started) * 1000)

        output = nodes[-1]
        target = f"{output['layer']}.{quote_ident(output['table'])}"
        schema_rows = con.execute(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position",
            [output["layer"], output["table"]],
        ).fetchall()
        preview = con.execute(f"SELECT * FROM {target} LIMIT {int(limits.get('preview_rows', 50))}").fetchall()
        payload = {"columns": [r[0] for r in schema_rows], "rows": [[json_value(v) for v in row] for row in preview]}
        preview_path.parent.mkdir(parents=True, exist_ok=True)
        tmp = preview_path.with_suffix(".tmp")
        tmp.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
        tmp.replace(preview_path)
        return {
            "status": "succeeded",
            "steps": steps,
            "output": {"layer": output["layer"], "table": output["table"], "row_count": steps[-1]["rows"],
                       "columns": [{"name": n, "type": t} for n, t in schema_rows]},
            "sources": sorted(sources),
            "raw_sources": sorted(raw_sources),
        }
    finally:
        con.close()
