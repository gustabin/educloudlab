"""Registry of runner operations. Adding an op: implement it, register it here, add pytest coverage
(success, limits, malicious input) and document it in docs/architecture/EXECUTION.md."""
from .csv_ops import drop_table, ingest, profile

OPS = {
    "profile": profile,
    "ingest": ingest,
    "drop_table": drop_table,
}
