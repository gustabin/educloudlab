"""Raw file profiling (raw layer) and ingestion into the workspace lakehouse (bronze layer).

Formats: CSV (strict parsing), JSON (array of objects or NDJSON) and Parquet (M7). Every reader runs inside the
DuckDB sandbox (configure()) under the same row/column caps and OS memory cap; JSON nesting beyond 10 levels is
kept as JSON text (maximum_depth) and the upload size cap bounds every object.

Both operations run trusted SQL built here; paths come from the dispatcher and are confined to the storage root.
Student-supplied SQL is never executed by these operations (that is the SQL Lab sandbox, M5).
"""
from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any

import duckdb

from .common import (
    connect,
    DELIMITERS,
    LAYERS,
    RunnerError,
    configure,
    confined,
    enforce_lakehouse_size,
    identifier,
    json_value,
    normalise_columns,
    quote_ident,
    safe_error_message,
)

# skip = 0 is essential: otherwise the sniffer may silently skip leading lines (treating a malformed later
# row as the header) and drop data. Explicit delimiter + strict mode make malformed files fail loudly.
STRICT_OPTIONS = (
    "delim = ?, quote = '\"', escape = '\"', header = true, skip = 0, strict_mode = true, "
    "null_padding = false, ignore_errors = false, sample_size = 20480"
)


MAX_HEADER_BYTES = 1024 * 1024


def detect_delimiter(path: Path, max_columns: int = 100) -> str:
    """Picks the most frequent candidate delimiter in the header line (',' when none is present).

    Also enforces the column limit and a header size limit *before* DuckDB sniffs the file, so a pathological
    header (e.g. a megabyte of delimiters) cannot make schema detection expensive.
    """
    with path.open("r", encoding="utf-8-sig", errors="replace", newline="") as handle:
        header = handle.readline(MAX_HEADER_BYTES)
        more = handle.read(1) != ""
    if not header.endswith(("\n", "\r")) and more:
        raise RunnerError("HEADER_TOO_LONG", "La primera línea (cabecera) del archivo es demasiado larga.")
    counts = {d: header.count(d) for d in DELIMITERS}
    best = max(counts, key=lambda d: counts[d])
    if counts[best] + 1 > max_columns:
        raise RunnerError("TOO_MANY_COLUMNS", f"El archivo tiene {counts[best] + 1} columnas; el máximo es {max_columns}.")
    return best if counts[best] > 0 else ","


FORMATS = ("csv", "json", "parquet")
JSON_OPTIONS = "format = 'auto', maximum_object_size = 1048576, sample_size = 20480, maximum_depth = 10"


class Source:
    """One raw file and how to read it: ``sql(columns)`` is a SELECT over the file, ``params`` its bound values."""

    def __init__(self, path: Path, fmt: str, limits: dict[str, Any]) -> None:
        if fmt not in FORMATS:
            raise RunnerError("BAD_REQUEST", "Formato no soportado.")
        self.path = path
        self.format = fmt
        if fmt == "csv":
            self.delimiter = detect_delimiter(path, int(limits.get("max_columns", 100)))
            self.reader = f"read_csv(?, {STRICT_OPTIONS})"
            self.params: list[Any] = [str(path), self.delimiter]
        elif fmt == "json":
            self.delimiter = None
            self.reader = f"read_json(?, {JSON_OPTIONS})"
            self.params = [str(path)]
        else:
            self.delimiter = None
            self.reader = "read_parquet(?)"
            self.params = [str(path)]

    def sql(self, columns_sql: str = "*") -> str:
        return f"SELECT {columns_sql} FROM {self.reader}"

    def parse_error(self, exc: Exception) -> RunnerError:
        if self.format == "csv":
            line = re.search(r"[Ll]ine:?\s*(\d+)", str(exc))
            where = f" (línea {line.group(1)})" if line else ""
            return RunnerError(
                "CSV_PARSE_ERROR",
                f"El archivo no es un CSV válido{where}: revisa que todas las filas tengan el mismo número de columnas "
                "y que las comillas estén cerradas.",
            )
        if self.format == "json":
            return RunnerError(
                "JSON_PARSE_ERROR",
                "El archivo no es un JSON válido: debe ser una lista de objetos o un objeto JSON por línea (NDJSON).",
            )
        return RunnerError("PARQUET_ERROR", "El archivo Parquet no se pudo leer (¿está dañado o incompleto?).")


def _describe(con: duckdb.DuckDBPyConnection, source: Source) -> list[tuple[str, str]]:
    try:
        rows = con.execute(f"DESCRIBE {source.sql()}", source.params).fetchall()
    except duckdb.Error as exc:
        raise source.parse_error(exc) from exc
    return [(str(r[0]), str(r[1])) for r in rows]


def _check_limits(con, source: Source, columns: list[tuple[str, str]], limits: dict[str, Any]) -> int:
    max_columns = int(limits.get("max_columns", 100))
    if len(columns) > max_columns:
        raise RunnerError("TOO_MANY_COLUMNS", f"El archivo tiene {len(columns)} columnas; el máximo es {max_columns}.")
    try:
        rows = int(con.execute(f"SELECT count(*) FROM {source.reader}", source.params).fetchone()[0])
    except duckdb.Error as exc:
        if source.format == "csv":
            raise RunnerError("CSV_PARSE_ERROR", "El archivo contiene filas no válidas: " + safe_error_message(exc)) from exc
        raise source.parse_error(exc) from exc
    max_rows = int(limits.get("max_rows", 500_000))
    if rows > max_rows:
        raise RunnerError("TOO_MANY_ROWS", f"El archivo tiene {rows} filas; el máximo es {max_rows}.")
    return rows


def _source(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> Source:
    path = confined(args.get("file_path") or args.get("csv_path", ""), allowed_root)
    return Source(path, str(args.get("format", "csv")), limits)


def _write_preview(path: Path, columns: list[str], rows: list[tuple[Any, ...]]) -> None:
    payload = {"columns": columns, "rows": [[json_value(v) for v in row] for row in rows]}
    tmp = path.with_suffix(".tmp")
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    tmp.replace(path)


def profile(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    """Schema discovery + row count + preview of an uploaded (raw) file."""
    source = _source(args, limits, allowed_root)
    preview_path = confined(args.get("preview_path", ""), allowed_root, must_exist=False)
    con = connect(":memory:")
    try:
        configure(con, limits, allowed_root)
        columns = _describe(con, source)
        row_count = _check_limits(con, source, columns, limits)
        preview_rows = int(limits.get("preview_rows", 50))
        try:
            rows = con.execute(f"{source.sql()} LIMIT {preview_rows}", source.params).fetchall()
        except duckdb.Error as exc:
            raise source.parse_error(exc) from exc
        _write_preview(preview_path, [c[0] for c in columns], rows)
        suggested = normalise_columns([c[0] for c in columns])
        return {
            "format": source.format,
            "delimiter": source.delimiter,
            "row_count": row_count,
            "columns": [
                {"name": name, "type": typ, "suggested_name": sug}
                for (name, typ), sug in zip(columns, suggested)
            ],
        }
    finally:
        con.close()


def ingest(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    """Creates (or replaces) <layer>.<table> in the workspace lakehouse from a raw file with normalised columns."""
    source = _source(args, limits, allowed_root)
    lakehouse = confined(args.get("lakehouse_path", ""), allowed_root, must_exist=False)
    preview_path = confined(args.get("preview_path", ""), allowed_root, must_exist=False)
    layer = args.get("layer", "bronze")
    if layer not in LAYERS:
        raise RunnerError("BAD_REQUEST", "Capa no válida.")
    table = identifier(args.get("table"))

    lakehouse.parent.mkdir(parents=True, exist_ok=True)
    con = connect(str(lakehouse))
    try:
        configure(con, limits, allowed_root)
        columns = _describe(con, source)
        row_count = _check_limits(con, source, columns, limits)
        targets = normalise_columns([c[0] for c in columns])
        projection = ", ".join(f"{quote_ident(src)} AS {quote_ident(dst)}" for (src, _), dst in zip(columns, targets))
        for schema in LAYERS:
            con.execute(f"CREATE SCHEMA IF NOT EXISTS {schema}")
        target = f"{layer}.{quote_ident(table)}"
        try:
            con.execute(f"CREATE OR REPLACE TABLE {target} AS {source.sql(projection)}", source.params)
        except duckdb.Error as exc:
            raise source.parse_error(exc) from exc
        problem = enforce_lakehouse_size(con, lakehouse, limits)
        if problem is not None:
            con.execute(f"DROP TABLE {target}")
            con.execute("CHECKPOINT")
            raise RunnerError(*problem)
        schema_rows = con.execute(
            "SELECT column_name, data_type FROM information_schema.columns "
            "WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position",
            [layer, table],
        ).fetchall()
        preview_rows = int(limits.get("preview_rows", 50))
        rows = con.execute(f"SELECT * FROM {target} LIMIT {preview_rows}").fetchall()
        _write_preview(preview_path, [r[0] for r in schema_rows], rows)
        return {
            "layer": layer,
            "table": table,
            "row_count": row_count,
            "columns": [
                {"name": name, "type": typ, "source_name": src}
                for (name, typ), (src, _) in zip(schema_rows, columns)
            ],
        }
    finally:
        con.close()


def drop_table(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    """Drops <layer>.<table> from the lakehouse (dataset deletion)."""
    lakehouse = confined(args.get("lakehouse_path", ""), allowed_root, must_exist=False)
    layer = args.get("layer")
    if layer not in LAYERS:
        raise RunnerError("BAD_REQUEST", "Capa no válida.")
    table = identifier(args.get("table"))
    if not lakehouse.exists():
        return {"dropped": False}
    con = connect(str(lakehouse))
    try:
        configure(con, limits, allowed_root)
        con.execute(f"DROP TABLE IF EXISTS {layer}.{quote_ident(table)}")
        return {"dropped": True}
    finally:
        con.close()
