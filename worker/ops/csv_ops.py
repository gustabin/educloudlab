"""CSV profiling (raw layer) and ingestion into the workspace lakehouse (bronze layer).

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


def _read(columns_sql: str = "*") -> str:
    """SELECT over the CSV with strict parsing; parameters: [path, delimiter]."""
    return f"SELECT {columns_sql} FROM read_csv(?, {STRICT_OPTIONS})"


def _describe(con: duckdb.DuckDBPyConnection, csv: Path, delimiter: str) -> list[tuple[str, str]]:
    try:
        rows = con.execute(f"DESCRIBE {_read()}", [str(csv), delimiter]).fetchall()
    except duckdb.Error as exc:
        line = re.search(r"[Ll]ine:?\s*(\d+)", str(exc))
        where = f" (línea {line.group(1)})" if line else ""
        raise RunnerError(
            "CSV_PARSE_ERROR",
            f"El archivo no es un CSV válido{where}: revisa que todas las filas tengan el mismo número de columnas "
            "y que las comillas estén cerradas.",
        ) from exc
    return [(str(r[0]), str(r[1])) for r in rows]


def _check_limits(con, csv: Path, delimiter: str, columns: list[tuple[str, str]], limits: dict[str, Any]) -> int:
    max_columns = int(limits.get("max_columns", 100))
    if len(columns) > max_columns:
        raise RunnerError("TOO_MANY_COLUMNS", f"El archivo tiene {len(columns)} columnas; el máximo es {max_columns}.")
    try:
        rows = int(con.execute(f"SELECT count(*) FROM read_csv(?, {STRICT_OPTIONS})", [str(csv), delimiter]).fetchone()[0])
    except duckdb.Error as exc:
        raise RunnerError("CSV_PARSE_ERROR", "El archivo contiene filas no válidas: " + safe_error_message(exc)) from exc
    max_rows = int(limits.get("max_rows", 500_000))
    if rows > max_rows:
        raise RunnerError("TOO_MANY_ROWS", f"El archivo tiene {rows} filas; el máximo es {max_rows}.")
    return rows


def _write_preview(path: Path, columns: list[str], rows: list[tuple[Any, ...]]) -> None:
    payload = {"columns": columns, "rows": [[json_value(v) for v in row] for row in rows]}
    tmp = path.with_suffix(".tmp")
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    tmp.replace(path)


def profile(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    """Schema discovery + row count + preview of an uploaded (raw) CSV file."""
    csv = confined(args.get("csv_path", ""), allowed_root)
    preview_path = confined(args.get("preview_path", ""), allowed_root, must_exist=False)
    delimiter = detect_delimiter(csv, int(limits.get("max_columns", 100)))
    con = duckdb.connect(":memory:")
    try:
        configure(con, limits, allowed_root)
        columns = _describe(con, csv, delimiter)
        row_count = _check_limits(con, csv, delimiter, columns, limits)
        preview_rows = int(limits.get("preview_rows", 50))
        rows = con.execute(f"{_read()} LIMIT {preview_rows}", [str(csv), delimiter]).fetchall()
        _write_preview(preview_path, [c[0] for c in columns], rows)
        suggested = normalise_columns([c[0] for c in columns])
        return {
            "delimiter": delimiter,
            "row_count": row_count,
            "columns": [
                {"name": name, "type": typ, "suggested_name": sug}
                for (name, typ), sug in zip(columns, suggested)
            ],
        }
    finally:
        con.close()


def ingest(args: dict[str, Any], limits: dict[str, Any], allowed_root: str) -> dict[str, Any]:
    """Creates (or replaces) <layer>.<table> in the workspace lakehouse from a raw CSV with normalised columns."""
    csv = confined(args.get("csv_path", ""), allowed_root)
    lakehouse = confined(args.get("lakehouse_path", ""), allowed_root, must_exist=False)
    preview_path = confined(args.get("preview_path", ""), allowed_root, must_exist=False)
    layer = args.get("layer", "bronze")
    if layer not in LAYERS:
        raise RunnerError("BAD_REQUEST", "Capa no válida.")
    table = identifier(args.get("table"))

    delimiter = detect_delimiter(csv, int(limits.get("max_columns", 100)))
    lakehouse.parent.mkdir(parents=True, exist_ok=True)
    con = duckdb.connect(str(lakehouse))
    try:
        configure(con, limits, allowed_root)
        columns = _describe(con, csv, delimiter)
        row_count = _check_limits(con, csv, delimiter, columns, limits)
        targets = normalise_columns([c[0] for c in columns])
        projection = ", ".join(f"{quote_ident(src)} AS {quote_ident(dst)}" for (src, _), dst in zip(columns, targets))
        for schema in LAYERS:
            con.execute(f"CREATE SCHEMA IF NOT EXISTS {schema}")
        target = f"{layer}.{quote_ident(table)}"
        con.execute(f"CREATE OR REPLACE TABLE {target} AS {_read(projection)}", [str(csv), delimiter])
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
    con = duckdb.connect(str(lakehouse))
    try:
        configure(con, limits, allowed_root)
        con.execute(f"DROP TABLE IF EXISTS {layer}.{quote_ident(table)}")
        return {"dropped": True}
    finally:
        con.close()
