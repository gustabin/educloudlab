"""Shared helpers: limits, path confinement, identifiers, safe errors and JSON-safe values."""
from __future__ import annotations

import datetime
import decimal
import math
import re
import unicodedata
import uuid
from pathlib import Path
from typing import Any

import duckdb

IDENTIFIER = re.compile(r"^[a-z][a-z0-9_]{0,62}$")
LAYERS = ("bronze", "silver", "gold")
DELIMITERS = (",", ";", "\t", "|")


class RunnerError(Exception):
    """Expected, user-safe failure with a stable error code."""

    def __init__(self, code: str, safe_message: str) -> None:
        super().__init__(code)
        self.code = code
        self.safe_message = safe_message


def confined(path: str, allowed_root: str, must_exist: bool = True) -> Path:
    """Resolves ``path`` and guarantees it stays below ``allowed_root`` (defence in depth)."""
    if not path or not allowed_root:
        raise RunnerError("BAD_REQUEST", "Ruta no válida.")
    root = Path(allowed_root).resolve()
    resolved = Path(path).resolve()
    if resolved != root and root not in resolved.parents:
        raise RunnerError("BAD_REQUEST", "Ruta fuera del almacenamiento permitido.")
    if must_exist and not resolved.exists():
        raise RunnerError("NOT_FOUND", "El archivo de datos no existe.")
    return resolved


def identifier(value: Any, what: str = "tabla") -> str:
    if not isinstance(value, str) or not IDENTIFIER.match(value):
        raise RunnerError("BAD_REQUEST", f"Nombre de {what} no válido.")
    return value


def quote_ident(name: str) -> str:
    return '"' + name.replace('"', '""') + '"'


def configure(
    con: duckdb.DuckDBPyConnection,
    limits: dict[str, Any],
    allowed_root: str,
    file_access: bool = True,
    allowed_files: list[str] | None = None,
) -> None:
    """Resource limits + filesystem sandbox, then locks the configuration so SQL cannot loosen it.

    DuckDB may only touch files below ``allowed_root`` (the job's workspace directory) - or no files at all when
    ``file_access`` is False (student SQL), except the exact ``allowed_files`` (pipelines: the raw inputs, read-only
    by construction); extensions can be neither installed nor auto-loaded (no httpfs/network).
    """
    threads = int(limits.get("threads", 2))
    memory_mb = int(limits.get("memory_mb", 512))
    con.execute(f"SET threads TO {max(1, min(threads, 8))}")
    con.execute(f"SET memory_limit = '{max(64, min(memory_mb, 4096))}MB'")
    con.execute("SET autoinstall_known_extensions = false")
    con.execute("SET autoload_known_extensions = false")
    # Neutral values for settings that otherwise reveal server paths (and no disk spilling outside the sandbox).
    con.execute("SET temp_directory = ''")
    con.execute("SET secret_directory = 'secrets'")
    con.execute("SET extension_directory = 'extensions'")
    con.execute("SET home_directory = ''")
    con.execute("SET allowed_directories = ?", [[str(Path(allowed_root).resolve())] if file_access else []])
    if allowed_files:
        con.execute("SET allowed_paths = ?", [[str(Path(f).resolve()) for f in allowed_files]])
    con.execute("SET enable_external_access = false")
    con.execute("SET lock_configuration = true")


def enforce_lakehouse_size(con: duckdb.DuckDBPyConnection, lakehouse: Path, limits: dict[str, Any]) -> tuple[str, str] | None:
    """Checkpoints and returns an error tuple when the workspace lakehouse file exceeds ``lakehouse_max_mb``."""
    con.execute("CHECKPOINT")
    max_mb = int(limits.get("lakehouse_max_mb", 200))
    size_mb = lakehouse.stat().st_size / (1024 * 1024) if lakehouse.exists() else 0
    if size_mb > max_mb:
        return ("LAKEHOUSE_FULL", f"El lakehouse de este workspace superaría su límite de {max_mb} MB. Elimina tablas que no uses.")
    return None


def normalise_columns(names: list[str]) -> list[str]:
    """Turns arbitrary CSV headers into unique snake_case SQL identifiers (bronze naming convention)."""
    out: list[str] = []
    seen: set[str] = set()
    for i, raw in enumerate(names):
        text = unicodedata.normalize("NFKD", str(raw)).encode("ascii", "ignore").decode("ascii").lower()
        text = re.sub(r"[^a-z0-9]+", "_", text).strip("_") or f"column_{i + 1}"
        if text[0].isdigit():
            text = "c_" + text
        text = text[:60]
        candidate, n = text, 2
        while candidate in seen:
            candidate = f"{text[:56]}_{n}"
            n += 1
        seen.add(candidate)
        out.append(candidate)
    return out


def json_value(value: Any, max_text: int = 200) -> Any:
    """Converts DuckDB values into JSON-safe, size-bounded values for previews."""
    if value is None or isinstance(value, bool):
        return value
    if isinstance(value, int):
        return value if abs(value) < 2**53 else str(value)
    if isinstance(value, float):
        return None if math.isnan(value) or math.isinf(value) else value
    if isinstance(value, decimal.Decimal):
        return str(value)
    if isinstance(value, (datetime.date, datetime.datetime, datetime.time)):
        return value.isoformat()
    if isinstance(value, datetime.timedelta):
        return str(value)
    if isinstance(value, uuid.UUID):
        return str(value)
    if isinstance(value, (bytes, bytearray, memoryview)):
        return "<binario>"
    text = str(value)
    return text if len(text) <= max_text else text[:max_text] + "…"


_PATH_PATTERNS = [
    re.compile(r"\\\\[^\s\"']+"),                      # UNC paths \\server\share\...
    re.compile(r"[A-Za-z]:[\\/][^\s\"']*"),          # Windows absolute paths
    re.compile(r"(?<![\w.])/(?:[\w.-]+/)+[\w.-]*"),   # POSIX absolute paths
]

# Literal prefixes (may contain spaces) that are removed before the generic patterns run.
_SENSITIVE_PREFIXES: list[str] = []


def register_sensitive_path(path: str) -> None:
    """Adds a path (and its slash/case variants) that must never appear in user-facing messages."""
    if path:
        for variant in {path, path.replace("\\", "/"), path.replace("/", "\\")}:
            _SENSITIVE_PREFIXES.append(variant)


def safe_error_message(exc: BaseException) -> str:
    """First line of an engine error with any filesystem path removed."""
    text = str(exc).splitlines()[0] if str(exc) else type(exc).__name__
    for prefix in sorted(_SENSITIVE_PREFIXES, key=len, reverse=True):
        text = re.sub(re.escape(prefix) + r"[^\"']*", "<archivo>", text, flags=re.IGNORECASE)
    for pattern in _PATH_PATTERNS:
        text = pattern.sub("<archivo>", text)
    text = text.replace('"<archivo>"', "<archivo>")
    return text[:300]
