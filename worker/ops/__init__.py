"""Registry of runner operations. Adding an op: implement it, register it here, add pytest coverage
(success, limits, malicious input) and document it in docs/architecture/EXECUTION.md."""
from .csv_ops import drop_table, ingest, profile
from .lab_ops import validate
from .pipeline_ops import pipeline
from .sql_ops import query, transform

OPS = {
    "profile": profile,
    "ingest": ingest,
    "drop_table": drop_table,
    "query": query,
    "transform": transform,
    "validate": validate,
    "pipeline": pipeline,
}
