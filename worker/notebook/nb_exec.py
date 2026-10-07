"""Notebook executor (M8, ADR-010). Runs INSIDE the sandbox container only (never on the host).

Reads /in/input.json ({"cells": [{"id", "source"}], "limits": {...}}), executes the code cells in order in one
namespace and prints ONE result line to the real stdout:

    __EDUCLOUD_NB__{"cells": [...], "artifacts": {...}, "status": "succeeded"|"failed"}

Student code runs in this same process, so everything printed here is student-controlled data: the PHP handler
parses it strictly, caps it, and never trusts it beyond the student's own run. Correctness (labs) is verified
server-side against SQL over the lakehouse, not by anything this file claims.

Helpers available to cells:
    lakehouse()                 read-only DuckDB connection to the workspace lakehouse copy (/data/lakehouse.duckdb)
    save_result(name, frame)    records a bounded artifact (pandas DataFrame or list of dicts)
"""
from __future__ import annotations

import ast
import io
import json
import math
import os
import re
import signal
import sys
import traceback
from contextlib import redirect_stderr, redirect_stdout
from typing import Any

MARKER = "__EDUCLOUD_NB__"
MAIN_PID = os.getpid()  # the executor; PID 1 is the image's `timeout -s KILL` guard (see Dockerfile)
MAX_RESULT_CHARS = 1_000_000  # the dispatcher rejects longer result lines (bounded parsing on the host)
NAME = re.compile(r"^[a-z][a-z0-9_]{0,39}$")
DEFAULTS = {"cell_timeout_s": 30, "max_text": 20_000, "max_rows": 50, "max_artifact_rows": 1000, "max_artifacts": 5,
            "max_cell_chars": 200}


class CellTimeout(BaseException):
    """Raised by the alarm; a BaseException so a student's `except Exception` cannot swallow it."""


class CappedText(io.TextIOBase):
    """A text sink that keeps at most `limit` characters (the rest is counted, not stored)."""

    def __init__(self, limit: int) -> None:
        self.limit = limit
        self.parts: list[str] = []
        self.size = 0
        self.dropped = 0

    def writable(self) -> bool:
        return True

    def write(self, s: str) -> int:  # type: ignore[override]
        s = str(s)
        room = self.limit - self.size
        if room > 0:
            self.parts.append(s[:room])
            self.size += min(len(s), room)
        self.dropped += max(0, len(s) - max(room, 0))
        return len(s)

    def text(self) -> str:
        out = "".join(self.parts)
        return out + (f"\n… ({self.dropped} caracteres omitidos)" if self.dropped else "")


def _value(v: Any, max_chars: int) -> Any:
    if v is None or isinstance(v, bool):
        return v
    if isinstance(v, int):
        return v if abs(v) < 2**53 else str(v)
    if isinstance(v, float):
        return None if math.isnan(v) or math.isinf(v) else v
    text = str(v)
    return text if len(text) <= max_chars else text[:max_chars] + "…"


def _table(frame: Any, max_rows: int, max_chars: int) -> dict[str, Any] | None:
    """pandas DataFrame (or list of dicts) → {"columns", "rows", "total_rows"} bounded."""
    try:
        import pandas as pd  # noqa: PLC0415 (imported lazily: cells may not need pandas)
    except ImportError:  # pragma: no cover
        pd = None
    if pd is not None and isinstance(frame, pd.Series):
        frame = frame.to_frame()
    if pd is not None and isinstance(frame, pd.DataFrame):
        columns = [str(c)[:100] for c in frame.columns][:100]
        rows = [[_value(x, max_chars) for x in row[:100]] for row in frame.head(max_rows).itertuples(index=False, name=None)]
        return {"columns": columns, "rows": rows, "total_rows": int(len(frame))}
    if isinstance(frame, list) and all(isinstance(r, dict) for r in frame):
        columns = list(dict.fromkeys(str(k)[:100] for r in frame for k in r))[:100]
        rows = [[_value(r.get(c), max_chars) for c in columns] for r in frame[:max_rows]]
        return {"columns": columns, "rows": rows, "total_rows": len(frame)}
    return None


def _kill_others() -> None:
    """Kills every other process of the container before the result line is printed.

    Forked children inherit this executor (and its stdout): left alive, one could print a later result line or
    keep running cells. kill(-1) reaches every process the user may signal except the caller and the container's
    PID 1 (the `timeout` guard, protected by the kernel). A forked child reaching this point exits silently instead.
    Note: student code runs in THIS process and can always print a forged line and exit; results are self-reported
    (docs/architecture/NOTEBOOKS.md) and never trusted beyond the student's own run.
    """
    if os.getpid() != MAIN_PID:
        os._exit(0)
    try:
        os.kill(-1, signal.SIGKILL)
    except (ProcessLookupError, PermissionError):
        pass


def main() -> int:
    with open("/in/input.json", encoding="utf-8") as fh:
        request = json.load(fh)
    limits = {**DEFAULTS, **{k: int(v) for k, v in (request.get("limits") or {}).items() if k in DEFAULTS}}
    real_stdout = sys.stdout
    artifacts: dict[str, Any] = {}

    def lakehouse():  # type: ignore[no-untyped-def]
        import duckdb  # noqa: PLC0415

        con = duckdb.connect("/data/lakehouse.duckdb", read_only=True)
        # DuckDB shares one database instance per file inside the process: only the first connection configures it;
        # later ones find the configuration already locked (which is the goal).
        locked = con.execute("SELECT current_setting('lock_configuration')").fetchone()[0]
        if not locked:
            con.execute("SET enable_external_access = false")
            con.execute("SET lock_configuration = true")
        return con

    def save_result(name: str, frame: Any) -> None:
        if not isinstance(name, str) or not NAME.match(name):
            raise ValueError("save_result: el nombre debe ser minúsculas, números y _ (empieza por letra).")
        if name not in artifacts and len(artifacts) >= limits["max_artifacts"]:
            raise ValueError(f"save_result: máximo {limits['max_artifacts']} resultados por ejecución.")
        table = _table(frame, limits["max_artifact_rows"], limits["max_cell_chars"])
        if table is None:
            raise TypeError("save_result: guarda un DataFrame de pandas o una lista de diccionarios.")
        artifacts[name] = table

    namespace: dict[str, Any] = {"__name__": "__notebook__", "lakehouse": lakehouse, "save_result": save_result}

    def on_alarm(_signum: int, _frame: Any) -> None:
        raise CellTimeout()

    signal.signal(signal.SIGALRM, on_alarm)
    # Timeouts use SIGALRM and the dispatcher stops containers with SIGKILL: interactive signals have no meaning
    # here. An interrupt raised into the executor (e.g. delivered while many forked children exist) would
    # otherwise abort the run with a bogus KeyboardInterrupt.
    signal.signal(signal.SIGINT, signal.SIG_IGN)
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    results = []
    status = "succeeded"
    for cell in request.get("cells") or []:
        cell_id = str(cell.get("id", ""))[:40]
        source = str(cell.get("source", ""))
        out = CappedText(limits["max_text"])
        err = CappedText(limits["max_text"])
        result: dict[str, Any] = {"id": cell_id, "status": "succeeded", "stdout": "", "stderr": "", "value": None, "table": None,
                                  "error": None}
        try:
            tree = ast.parse(source, filename=f"celda {cell_id}", mode="exec")
            last = tree.body.pop() if tree.body and isinstance(tree.body[-1], ast.Expr) else None
            signal.alarm(limits["cell_timeout_s"])
            with redirect_stdout(out), redirect_stderr(err):
                exec(compile(tree, f"celda {cell_id}", "exec"), namespace)  # noqa: S102 (sandboxed by design)
                if last is not None:
                    value = eval(compile(ast.Expression(last.value), f"celda {cell_id}", "eval"), namespace)  # noqa: S307
                    if value is not None:
                        table = _table(value, limits["max_rows"], limits["max_cell_chars"])
                        if table is not None:
                            result["table"] = table
                        else:
                            text = repr(value)
                            result["value"] = text if len(text) <= limits["max_text"] else text[: limits["max_text"]] + "…"
        except CellTimeout:
            result["status"] = "failed"
            result["error"] = {"type": "TimeoutError", "message": f"La celda superó {limits['cell_timeout_s']} s."}
        except BaseException as exc:  # noqa: BLE001 (any student error, incl. SystemExit/KeyboardInterrupt)
            result["status"] = "failed"
            frames = traceback.extract_tb(exc.__traceback__)
            line = next((f.lineno for f in reversed(frames) if f.filename == f"celda {cell_id}"), None)
            if isinstance(exc, SyntaxError):
                line = exc.lineno
            result["error"] = {"type": type(exc).__name__, "message": str(exc)[:2000], "line": line}
        finally:
            signal.alarm(0)
        result["stdout"] = out.text()
        result["stderr"] = err.text()
        results.append(result)
        if result["status"] == "failed":
            status = "failed"
            break  # later cells depend on earlier ones: stop at the first error, like "run all"

    payload = json.dumps({"status": status, "cells": results, "artifacts": artifacts}, ensure_ascii=False, default=str)
    if len(payload) > MAX_RESULT_CHARS:
        # Keep the run outcome, drop the bulky parts: the host refuses oversized result lines.
        for r in results:
            r.update(stdout=r["stdout"][:2000], stderr=r["stderr"][:2000], table=None)
        payload = json.dumps({"status": "failed", "cells": results, "artifacts": {}, "too_large": True}, ensure_ascii=False, default=str)
        if len(payload) > MAX_RESULT_CHARS:
            payload = json.dumps({"status": "failed", "cells": [], "artifacts": {}, "too_large": True})
    _kill_others()
    real_stdout.write("\n" + MARKER + payload + "\n")
    real_stdout.flush()
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception:  # noqa: BLE001 (executor bug or unreadable input: report without details)
        if os.getpid() != MAIN_PID:
            os._exit(1)  # a forked child never reports
        sys.stdout = sys.__stdout__
        print("\n" + MARKER + json.dumps({"status": "failed", "cells": [], "artifacts": {}, "executor_error": True}))
        sys.exit(1)
    finally:
        os.environ.clear()
