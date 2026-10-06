"""Runner contract and CSV operations (run: worker\\.venv\\Scripts\\python -m pytest worker/tests)."""
from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

import duckdb
import pytest

WORKER = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(WORKER))

from ops.common import RunnerError, configure, normalise_columns, register_sensitive_path, safe_error_message  # noqa: E402
from ops.csv_ops import detect_delimiter, drop_table, ingest, profile  # noqa: E402

LIMITS = {"threads": 1, "memory_mb": 256, "max_rows": 1000, "max_columns": 20, "preview_rows": 5}


@pytest.fixture()
def root(tmp_path: Path) -> Path:
    (tmp_path / "raw").mkdir()
    return tmp_path


def write(root: Path, name: str, content: str | bytes) -> Path:
    path = root / "raw" / name
    path.write_bytes(content.encode("utf-8") if isinstance(content, str) else content)
    return path


CUSTOMERS = "Customer ID,Full Name,E-mail,Signup Date,amount\n1,Ana García,ana@x.com,2026-01-05,10.50\n2,Luis,,2026-02-10,7\n"


# ---------------------------------------------------------------- profile
def test_profile_detects_schema_rows_and_writes_preview(root: Path) -> None:
    csv = write(root, "c.csv", CUSTOMERS)
    out = profile({"csv_path": str(csv), "preview_path": str(root / "meta" / "p.json")}, LIMITS, str(root))
    assert out["row_count"] == 2
    assert out["delimiter"] == ","
    assert [c["type"] for c in out["columns"]] == ["BIGINT", "VARCHAR", "VARCHAR", "DATE", "DOUBLE"]
    assert [c["suggested_name"] for c in out["columns"]] == ["customer_id", "full_name", "e_mail", "signup_date", "amount"]
    preview = json.loads((root / "meta" / "p.json").read_text(encoding="utf-8"))
    assert preview["rows"][0] == [1, "Ana García", "ana@x.com", "2026-01-05", 10.5]
    assert preview["rows"][1][2] is None


@pytest.mark.parametrize(
    "content,delimiter",
    [("a;b\n1;2\n", ";"), ("a\tb\n1\t2\n", "\t"), ("a|b\n1|2\n", "|"), ("solo\n1\n2\n", ",")],
)
def test_delimiter_detection(root: Path, content: str, delimiter: str) -> None:
    assert detect_delimiter(write(root, "d.csv", content)) == delimiter


def test_utf8_bom_is_ignored(root: Path) -> None:
    csv = write(root, "bom.csv", b"\xef\xbb\xbfid,name\n1,ok\n")
    out = profile({"csv_path": str(csv), "preview_path": str(root / "p.json")}, LIMITS, str(root))
    assert out["columns"][0]["name"] == "id"


@pytest.mark.parametrize(
    "content",
    [
        "a,b\n1,2\n3,4,5\n6,7\n",         # extra field
        "a,b\n1,2\n\"x,3\n",              # unterminated quote
        "a,b\n1\n2,3\n",                  # missing field
        "a,b\n1,2\n3,4,5\n",              # extra field on the LAST line (the sniffer must not skip lines)
        "notas del export\na,b\n1,2\n",   # preamble line before the header
    ],
)
def test_malformed_csv_is_rejected_with_safe_message(root: Path, content: str) -> None:
    csv = write(root, "bad.csv", content)
    with pytest.raises(RunnerError) as err:
        profile({"csv_path": str(csv), "preview_path": str(root / "p.json")}, LIMITS, str(root))
    assert err.value.code == "CSV_PARSE_ERROR"
    assert str(root) not in err.value.safe_message
    assert ":\\" not in err.value.safe_message and ":/" not in err.value.safe_message


def test_row_and_column_limits(root: Path) -> None:
    many_rows = write(root, "rows.csv", "a\n" + "\n".join(str(i) for i in range(1001)) + "\n")
    with pytest.raises(RunnerError) as err:
        profile({"csv_path": str(many_rows), "preview_path": str(root / "p.json")}, LIMITS, str(root))
    assert err.value.code == "TOO_MANY_ROWS"

    wide = write(root, "wide.csv", ",".join(f"c{i}" for i in range(21)) + "\n" + ",".join("1" * 21) + "\n")
    with pytest.raises(RunnerError) as err:
        profile({"csv_path": str(wide), "preview_path": str(root / "p.json")}, LIMITS, str(root))
    assert err.value.code == "TOO_MANY_COLUMNS"


@pytest.mark.parametrize("path_key", ["csv_path", "preview_path"])
def test_paths_outside_the_allowed_root_are_refused(root: Path, tmp_path_factory, path_key: str) -> None:
    outside = tmp_path_factory.mktemp("outside") / "x.csv"
    outside.write_text("a\n1\n", encoding="utf-8")
    args = {"csv_path": str(write(root, "ok.csv", "a\n1\n")), "preview_path": str(root / "p.json")}
    args[path_key] = str(outside)
    with pytest.raises(RunnerError) as err:
        profile(args, LIMITS, str(root))
    assert err.value.code == "BAD_REQUEST"


def test_traversal_in_path_is_refused(root: Path) -> None:
    write(root, "ok.csv", "a\n1\n")
    with pytest.raises(RunnerError):
        profile({"csv_path": str(root / "raw" / ".." / ".." / "escape.csv"), "preview_path": str(root / "p.json")}, LIMITS, str(root))


# ---------------------------------------------------------------- ingest / drop
def test_ingest_creates_bronze_table_with_normalised_columns(root: Path) -> None:
    csv = write(root, "c.csv", CUSTOMERS)
    lake = root / "lakehouse.duckdb"
    out = ingest(
        {"csv_path": str(csv), "lakehouse_path": str(lake), "layer": "bronze", "table": "customers", "preview_path": str(root / "b.json")},
        LIMITS,
        str(root),
    )
    assert out["row_count"] == 2
    assert [c["name"] for c in out["columns"]] == ["customer_id", "full_name", "e_mail", "signup_date", "amount"]
    assert out["columns"][0]["source_name"] == "Customer ID"
    con = duckdb.connect(str(lake))
    try:
        assert con.execute("SELECT count(*) FROM bronze.customers").fetchone()[0] == 2
        schemas = {r[0] for r in con.execute("SELECT schema_name FROM information_schema.schemata").fetchall()}
        assert {"bronze", "silver", "gold"} <= schemas
    finally:
        con.close()

    drop_table({"lakehouse_path": str(lake), "layer": "bronze", "table": "customers"}, LIMITS, str(root))
    con = duckdb.connect(str(lake))
    try:
        assert con.execute("SELECT count(*) FROM information_schema.tables WHERE table_name = 'customers'").fetchone()[0] == 0
    finally:
        con.close()


@pytest.mark.parametrize("table", ["Customers", "x; DROP TABLE y", "a-b", "", "1abc", "a" * 64])
def test_ingest_rejects_invalid_table_identifiers(root: Path, table: str) -> None:
    csv = write(root, "c.csv", CUSTOMERS)
    with pytest.raises(RunnerError) as err:
        ingest({"csv_path": str(csv), "lakehouse_path": str(root / "l.duckdb"), "layer": "bronze", "table": table,
                "preview_path": str(root / "p.json")}, LIMITS, str(root))
    assert err.value.code == "BAD_REQUEST"


def test_ingest_rejects_unknown_layer(root: Path) -> None:
    csv = write(root, "c.csv", CUSTOMERS)
    with pytest.raises(RunnerError):
        ingest({"csv_path": str(csv), "lakehouse_path": str(root / "l.duckdb"), "layer": "main", "table": "t",
                "preview_path": str(root / "p.json")}, LIMITS, str(root))


def test_header_injection_cannot_break_out_of_identifiers(root: Path) -> None:
    csv = write(root, "evil.csv", '"a"" AS x, 1 AS y FROM read_csv(\'secret\') --",b\n1,2\n')
    out = ingest({"csv_path": str(csv), "lakehouse_path": str(root / "l.duckdb"), "layer": "bronze", "table": "evil",
                  "preview_path": str(root / "p.json")}, LIMITS, str(root))
    assert [c["name"] for c in out["columns"]][1] == "b"
    assert out["row_count"] == 1


# ---------------------------------------------------------------- helpers
def test_normalise_columns_is_unique_and_sql_safe() -> None:
    assert normalise_columns(["Name", "name", "NAME", "1st", "", "Año €"]) == ["name", "name_2", "name_3", "c_1st", "column_5", "ano"]


def test_safe_error_message_strips_paths() -> None:
    msg = safe_error_message(Exception('IO Error: No files found that match "C:/Users/x/data/t/w/raw/a.csv" and /var/lib/x/y.csv'))
    assert "C:/Users" not in msg and "/var/lib" not in msg
    assert "<archivo>" in msg


# ---------------------------------------------------------------- process contract
def test_runner_process_writes_response_and_never_raises(root: Path) -> None:
    csv = write(root, "c.csv", CUSTOMERS)
    request = root / "req.json"
    response = root / "res.json"
    request.write_text(json.dumps({
        "op": "profile", "allowed_root": str(root), "limits": LIMITS,
        "args": {"csv_path": str(csv), "preview_path": str(root / "p.json")},
    }), encoding="utf-8")
    done = subprocess.run([sys.executable, str(WORKER / "runner.py"), str(request), str(response)], cwd=WORKER, timeout=60)
    assert done.returncode == 0
    body = json.loads(response.read_text(encoding="utf-8"))
    assert body["ok"] is True and body["data"]["row_count"] == 2 and "duration_ms" in body["stats"]

    request.write_text(json.dumps({"op": "rm_rf", "args": {}}), encoding="utf-8")
    subprocess.run([sys.executable, str(WORKER / "runner.py"), str(request), str(response)], cwd=WORKER, timeout=60, check=True)
    assert json.loads(response.read_text(encoding="utf-8"))["error_code"] == "UNKNOWN_OP"


# ---------------------------------------------------------------- sandbox (selected by the security gate: -k sandbox)
def test_sandbox_blocks_files_outside_the_allowed_root(root: Path, tmp_path_factory) -> None:
    outside = tmp_path_factory.mktemp("secret") / "secret.csv"
    outside.write_text("password\nhunter2\n", encoding="utf-8")
    con = duckdb.connect(":memory:")
    try:
        configure(con, LIMITS, str(root))
        with pytest.raises(duckdb.Error):
            con.execute("SELECT * FROM read_csv(?)", [str(outside)]).fetchall()
        with pytest.raises(duckdb.Error):
            con.execute("COPY (SELECT 1) TO ?", [str(outside.parent / "exfil.csv")])
    finally:
        con.close()


def test_sandbox_configuration_is_locked_and_extensions_disabled(root: Path) -> None:
    con = duckdb.connect(":memory:")
    try:
        configure(con, LIMITS, str(root))
        for statement in ("SET enable_external_access = true", "SET allowed_directories = ['C:/']",
                          "SET lock_configuration = false", "SET autoinstall_known_extensions = true"):
            with pytest.raises(duckdb.Error):
                con.execute(statement)
        with pytest.raises(duckdb.Error):
            con.execute("INSTALL httpfs")
    finally:
        con.close()


def test_sandbox_limits_reject_a_huge_header_before_duckdb(root: Path) -> None:
    wide = write(root, "wide.csv", "," * 2_000_000 + "\n1\n")
    with pytest.raises(RunnerError) as err:
        profile({"csv_path": str(wide), "preview_path": str(root / "p.json")}, LIMITS, str(root))
    assert err.value.code in ("TOO_MANY_COLUMNS", "HEADER_TOO_LONG")

    no_newline = write(root, "line.csv", "a" * (1024 * 1024 + 10))
    with pytest.raises(RunnerError) as err:
        profile({"csv_path": str(no_newline), "preview_path": str(root / "p.json")}, LIMITS, str(root))
    assert err.value.code == "HEADER_TOO_LONG"


def test_sandbox_error_messages_hide_paths_with_spaces_and_unc() -> None:
    register_sensitive_path(r"C:\Data Store\edu cloud")
    msg = safe_error_message(Exception(r'IO Error: Cannot open "C:\Data Store\edu cloud\t\x\raw\a.csv" or \\nas\share\x.csv'))
    assert "Data Store" not in msg and "nas" not in msg and "share" not in msg
