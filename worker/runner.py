"""EduCloud Lab execution runner (ADR-005).

Invoked only by scripts/dispatcher.php as a short-lived child process:

    python runner.py <request.json> <response.json>

The request holds the operation, its arguments (absolute paths computed by the dispatcher from database ids)
and resource limits. The runner never receives database credentials or secrets, never opens MySQL, and only
touches paths below ``allowed_root``. It always writes a JSON response:

    {"ok": true,  "data": {...}, "stats": {"duration_ms": n}}
    {"ok": false, "error_code": "...", "safe_message": "...", "stats": {...}}

Error messages are sanitised (no filesystem paths) before leaving the runner.
"""
from __future__ import annotations

import json
import sys
import time
from pathlib import Path

# The dispatcher starts Python in isolated mode (-I), which does not put the script directory on sys.path.
sys.path.insert(0, str(Path(__file__).resolve().parent))

from ops import OPS  # noqa: E402
from ops.common import RunnerError, register_sensitive_path, safe_error_message  # noqa: E402
from ops.process_limits import apply_memory_cap, peak_memory_mb  # noqa: E402


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print("usage: runner.py <request.json> <response.json>", file=sys.stderr)
        return 2
    request_path, response_path = Path(argv[1]), Path(argv[2])
    started = time.perf_counter()
    try:
        request = json.loads(request_path.read_text(encoding="utf-8"))
        limits = request.get("limits", {})
        # Hard OS cap on the whole process (DuckDB's memory_limit does not cover Python objects).
        apply_memory_cap(int(limits.get("process_memory_mb", 0)))
        register_sensitive_path(str(request.get("allowed_root", "")))
        register_sensitive_path(str(Path(sys.executable).parent))
        op = request.get("op")
        if op not in OPS:
            raise RunnerError("UNKNOWN_OP", "Operación no soportada.")
        data = OPS[op](request.get("args", {}), request.get("limits", {}), request.get("allowed_root", ""))
        response = {"ok": True, "data": data}
    except RunnerError as exc:
        response = {"ok": False, "error_code": exc.code, "safe_message": exc.safe_message}
    except MemoryError:
        response = {"ok": False, "error_code": "OUT_OF_MEMORY", "safe_message": "La operación necesitó demasiada memoria y se canceló."}
    except Exception as exc:  # noqa: BLE001 - last resort: never leak internals
        response = {"ok": False, "error_code": "RUNNER_ERROR", "safe_message": safe_error_message(exc)}
    response["stats"] = {"duration_ms": int((time.perf_counter() - started) * 1000), "peak_memory_mb": peak_memory_mb()}
    response_path.write_text(json.dumps(response, ensure_ascii=False, default=str), encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
