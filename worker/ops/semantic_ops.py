"""Semantic layer (M9): op "semantic".

A semantic model describes ONE fact table, many-to-one relationships from the fact to dimension tables (a star),
measures (an aggregation over a fact column, or the ratio of two measures) and dimensions (a column of the fact or of
a related table, optionally truncated to a date grain). Students never write this SQL: each request (measures,
dimensions, filters, order, limit) is compiled here from fixed templates.

- Every identifier is validated (tables in silver/gold only, ^[a-z_][a-z0-9_]*$ columns) and quoted.
- Measure and dimension names are validated identifiers used only as output aliases.
- Aggregations and grains come from allowlists; every filter value is a bound parameter.
- Only the dimension tables a request needs are joined (LEFT JOIN, so facts are never dropped).

Execution: read-only connection, no file access, locked configuration, an interrupt per query, a row cap per
query and a byte budget for the whole job. A failing query (e.g. a dropped table) is reported in its own result and
does not fail the others, so a dashboard renders whatever it can.
"""
from __future__ import annotations

import json
import re
import time
from typing import Any

import duckdb

from .common import RunnerError, confined, configure, json_value, quote_ident
from .sql_ops import _Interrupter, _open, _sql_error

NAME = re.compile(r"^[a-z][a-z0-9_]{0,62}$")
COLUMN = re.compile(r"^[a-z_][a-z0-9_]{0,62}$")
TABLE = re.compile(r"^(silver|gold)\.([a-z][a-z0-9_]{0,62})$")
KEY = re.compile(r"^[a-z0-9_]{1,40}$")
AGGREGATIONS = {
    "sum": "CAST(sum({c}) AS DOUBLE)",
    "avg": "CAST(avg({c}) AS DOUBLE)",
    "min": "CAST(min({c}) AS DOUBLE)",
    "max": "CAST(max({c}) AS DOUBLE)",
    "count": "count({c})",
    "count_distinct": "count(DISTINCT {c})",
}
GRAINS = {"day": "day", "week": "week", "month": "month", "quarter": "quarter", "year": "year"}
FILTER_OPS = {"eq": "= ?", "ne": "<> ?", "gte": ">= ?", "lte": "<= ?", "gt": "> ?", "lt": "< ?"}
MAX_QUERIES = 40
MAX_LIST = 100
DIMENSION_TEXT = 200


def _bad(message: str) -> RunnerError:
    return RunnerError("BAD_MODEL", message)


def _name(value: Any, what: str) -> str:
    if not isinstance(value, str) or not NAME.match(value):
        raise _bad(f"Nombre de {what} no válido.")
    return value


def _column(value: Any) -> str:
    if not isinstance(value, str) or not COLUMN.match(value):
        raise _bad("Nombre de columna no válido.")
    return quote_ident(value)


def _table(value: Any) -> str:
    match = TABLE.match(value) if isinstance(value, str) else None
    if not match:
        raise _bad("El modelo solo puede usar tablas de silver o gold (capa.tabla).")
    return f"{match.group(1)}.{quote_ident(match.group(2))}"


class Model:
    """A validated semantic model: SQL fragments keyed by measure and dimension names."""

    def __init__(self, raw: Any) -> None:
        if not isinstance(raw, dict):
            raise _bad("El modelo debe ser un objeto.")
        self.fact = _table(raw.get("fact"))
        # Relationship per dimension table: alias + join condition (fact column = dimension column).
        self.joins: dict[str, tuple[str, str]] = {}
        for i, rel in enumerate(raw.get("relationships") or []):
            if not isinstance(rel, dict):
                raise _bad("Relación no válida.")
            table = _table(rel.get("table"))
            if table in self.joins or table == self.fact:
                raise _bad("Cada tabla de dimensión se relaciona una sola vez con la tabla de hechos.")
            alias = f"d{i}"
            self.joins[table] = (alias, f"f.{_column(rel.get('fact_column'))} = {alias}.{_column(rel.get('column'))}")
        if len(self.joins) > 10:
            raise _bad("Demasiadas relaciones (máximo 10).")

        self.measures: dict[str, str] = {}
        ratios: list[tuple[str, Any]] = []
        for m in raw.get("measures") or []:
            if not isinstance(m, dict):
                raise _bad("Medida no válida.")
            name = _name(m.get("name"), "medida")
            if name in self.measures or any(name == r[0] for r in ratios):
                raise _bad(f"La medida {name} está repetida.")
            if "ratio" in m:
                ratios.append((name, m.get("ratio")))
                continue
            agg = m.get("agg")
            if agg not in AGGREGATIONS:
                raise _bad(f"Agregación no permitida en la medida {name}.")
            column = "*" if agg == "count" and m.get("column") in (None, "*") else f"f.{_column(m.get('column'))}"
            self.measures[name] = AGGREGATIONS[agg].format(c=column)
        base = dict(self.measures)  # ratios divide two AGGREGATED measures only (no nesting: bounded SQL size)
        for name, operands in ratios:
            if not (isinstance(operands, list) and len(operands) == 2 and all(isinstance(o, str) and o in base for o in operands)):
                raise _bad(f"La medida {name} divide dos medidas base del modelo.")
            self.measures[name] = f"(CAST({base[operands[0]]} AS DOUBLE) / NULLIF({base[operands[1]]}, 0))"
        if not self.measures or len(self.measures) > 50:
            raise _bad("El modelo necesita entre 1 y 50 medidas.")

        # Dimension name -> (SQL expression, table it needs or None for the fact, is_date).
        self.dimensions: dict[str, tuple[str, str | None, bool]] = {}
        for d in raw.get("dimensions") or []:
            if not isinstance(d, dict):
                raise _bad("Dimensión no válida.")
            name = _name(d.get("name"), "dimensión")
            if name in self.dimensions or name in self.measures:
                raise _bad(f"El nombre {name} está repetido en el modelo.")
            table = d.get("table")
            if table is None:
                alias, needs = "f", None
            else:
                needs = _table(table)
                if needs == self.fact:
                    alias, needs = "f", None
                elif needs in self.joins:
                    alias = self.joins[needs][0]
                else:
                    raise _bad(f"La dimensión {name} usa una tabla sin relación con la tabla de hechos.")
            column = f"{alias}.{_column(d.get('column'))}"
            grain = d.get("grain")
            if grain is None:
                self.dimensions[name] = (f"left(CAST({column} AS VARCHAR), {DIMENSION_TEXT})", needs, False)
            elif grain in GRAINS:
                self.dimensions[name] = (f"CAST(date_trunc('{GRAINS[grain]}', {column}) AS DATE)", needs, True)
            else:
                raise _bad(f"Granularidad de fecha no permitida en la dimensión {name}.")
        if len(self.dimensions) > 50:
            raise _bad("Demasiadas dimensiones (máximo 50).")

    def compile(self, request: Any, max_rows: int) -> tuple[str, list[Any]]:
        if not isinstance(request, dict):
            raise _bad("Consulta no válida.")
        measures = self._names(request.get("measures"), self.measures, "medida")
        dimensions = self._names(request.get("dimensions"), self.dimensions, "dimensión")
        if not measures and not dimensions:
            raise _bad("Elige al menos una medida o una dimensión.")
        if len(measures) > 10 or len(dimensions) > 3:
            raise _bad("Una consulta usa como máximo 10 medidas y 3 dimensiones.")

        needed: set[str] = set()
        where: list[str] = []
        params: list[Any] = []
        for f in request.get("filters") or []:
            if not isinstance(f, dict) or f.get("dimension") not in self.dimensions:
                raise _bad("Filtro sobre una dimensión desconocida.")
            expr, table, _ = self.dimensions[f["dimension"]]
            if table:
                needed.add(table)
            op, value = f.get("op"), f.get("value")
            if op in FILTER_OPS:
                if not _scalar(value):
                    raise _bad("El filtro necesita un valor.")
                where.append(f"{expr} {FILTER_OPS[op]}")
                params.append(value)
            elif op in ("in", "not_in"):
                if not isinstance(value, list) or not 1 <= len(value) <= MAX_LIST or not all(_scalar(v) for v in value):
                    raise _bad(f"El filtro {op} necesita una lista de 1 a {MAX_LIST} valores.")
                where.append(f"{expr} {'NOT IN' if op == 'not_in' else 'IN'} ({', '.join('?' for _ in value)})")
                params.extend(value)
            elif op == "between":
                if not isinstance(value, list) or len(value) != 2 or not all(_scalar(v) and v is not None for v in value):
                    raise _bad("El filtro between necesita [desde, hasta].")
                where.append(f"{expr} BETWEEN ? AND ?")
                params.extend(value)
            else:
                raise _bad("Operador de filtro no permitido.")
        for name in dimensions:
            if self.dimensions[name][1]:
                needed.add(str(self.dimensions[name][1]))

        select = [f"{self.dimensions[d][0]} AS {quote_ident(d)}" for d in dimensions]
        select += [f"{self.measures[m]} AS {quote_ident(m)}" for m in measures]
        joins = " ".join(f"LEFT JOIN {t} {self.joins[t][0]} ON {self.joins[t][1]}" for t in sorted(needed))
        sql = f"SELECT {'DISTINCT ' if not measures else ''}{', '.join(select)} FROM {self.fact} f {joins}"
        if where:
            sql += " WHERE " + " AND ".join(where)
        if measures and dimensions:
            sql += " GROUP BY " + ", ".join(str(i + 1) for i in range(len(dimensions)))

        order = request.get("order")
        output = dimensions + measures
        if isinstance(order, dict) and order.get("by") in output:
            direction = "DESC" if order.get("dir") == "desc" else "ASC"
            sql += f" ORDER BY {output.index(order['by']) + 1} {direction} NULLS LAST"
        elif order is not None:
            raise _bad("Orden no válido: elige una medida o dimensión de la consulta.")
        elif dimensions:
            sql += " ORDER BY " + ", ".join(str(i + 1) for i in range(len(dimensions)))
        limit = request.get("limit", max_rows)
        if not isinstance(limit, int) or isinstance(limit, bool) or not 1 <= limit <= max_rows:
            raise _bad(f"El límite debe estar entre 1 y {max_rows}.")
        sql += f" LIMIT {limit + 1}"
        return sql, params

    @staticmethod
    def _names(value: Any, known: dict[str, Any], what: str) -> list[str]:
        if value is None:
            return []
        if not isinstance(value, list) or not all(isinstance(v, str) for v in value) or len(set(value)) != len(value):
            raise _bad(f"Lista de {what}s no válida.")
        for v in value:
            if v not in known:
                raise _bad(f"La {what} {v} no existe en el modelo.")
        return list(value)

    def kinds(self, request: dict[str, Any]) -> list[str]:
        dims = request.get("dimensions") or []
        return ["date" if self.dimensions[d][2] else "dimension" for d in dims] + ["measure" for _ in request.get("measures") or []]


def _scalar(value: Any) -> bool:
    return value is None or isinstance(value, (bool, int, float)) or (isinstance(value, str) and len(value) <= 200)


def semantic(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    lakehouse = confined(args.get("lakehouse_path", ""), allowed_root, must_exist=False)
    model = Model(args.get("model"))
    queries = args.get("queries")
    if not isinstance(queries, list) or not 1 <= len(queries) <= MAX_QUERIES:
        raise _bad(f"Se ejecutan entre 1 y {MAX_QUERIES} consultas por trabajo.")
    max_rows = int(limits.get("semantic_max_rows", 1000))
    timeout_s = float(limits.get("sql_timeout_s", 10))
    budget = int(limits.get("sql_max_bytes", 1_500_000))
    started_all = time.perf_counter()
    results: dict[str, Any] = {}

    con = _open(lakehouse, read_only=True)
    try:
        configure(con, limits, allowed_root, file_access=False)
        for q in queries:
            key = q.get("key") if isinstance(q, dict) else None
            if not isinstance(key, str) or not KEY.match(key) or key in results:
                raise _bad("Clave de consulta no válida.")
            started = time.perf_counter()
            try:
                sql, params = model.compile(q, max_rows)
                limit = int(q.get("limit", max_rows))
                with _Interrupter(con, timeout_s) as guard:
                    try:
                        cursor = con.execute(sql, params)
                        names = [d[0] for d in cursor.description]
                        raw = cursor.fetchmany(limit + 1)
                    except duckdb.Error as exc:
                        raise _sql_error(exc, timeout_s, guard.fired) from exc
                truncated = len(raw) > limit
                rows = [[json_value(v, max_text=DIMENSION_TEXT) for v in r] for r in raw[:limit]]
                size = len(json.dumps(rows, ensure_ascii=False))
                if size > budget:
                    raise RunnerError("RESULT_TOO_LARGE", "El resultado es demasiado grande: añade filtros o reduce el límite.")
                budget -= size
                kinds = model.kinds(q)
                results[key] = {
                    "columns": [{"name": n, "role": k} for n, k in zip(names, kinds)],
                    "rows": rows,
                    "truncated": truncated,
                    "elapsed_ms": int((time.perf_counter() - started) * 1000),
                }
            except RunnerError as exc:
                results[key] = {"error": {"code": exc.code, "message": exc.safe_message}}
        return {"results": results, "elapsed_ms": int((time.perf_counter() - started_all) * 1000)}
    finally:
        con.close()
