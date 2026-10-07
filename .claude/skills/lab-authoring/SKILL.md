---
name: lab-authoring
description: Author, validate and test EduCloud Lab laboratories (labs/LAB-xxx/lab.json, instructions, hints, declarative checks, reference solutions, sample datasets). Use when creating or editing a lab or its checks.
---

# Lab Authoring

## Structure
```
labs/LAB-xxx/
  lab.json               # validated by labs/schema/lab.schema.json
  instructions.es.md     # one "## <task key>" section per task (CommonMark, HTML escaped)
  solution/solution.json # reference steps applied through the public API by tests/Labs (never served)
```
Sample data: `public/assets/datasets/retail/*.csv` (synthetic, CC0, deterministic: `php scripts/generate-retail-data.php`).

## Workflow
1. Define objectives, prerequisites, minutes and difficulty (master plan §28).
2. Write the tasks. Each task has a key, points, hints (with an optional penalty) and `checks[]`.
3. Use **only** the registered setup actions and check types (`labs/schema/lab.schema.json`, `docs/architecture/LAB_ENGINE.md`):
   - Setup: `create_resource`, `load_sample` (optionally `ingest_to: bronze.<t>`).
   - Metadata checks (PHP): `resource_exists` (name/config/tags subset), `resource_deleted`, `dataset_exists`,
     `container_exists` (optional lifecycle subset), `object_exists` (key or prefix; metadata subset, tier),
     `pipeline_has_nodes`, `pipeline_run_succeeded` (latest run, optional output table).
     M9: `semantic_model_has` and `dashboard_has_widgets` (match what measures/dimensions compute, not their names).
     M8: `notebook_run_succeeded`; data check `notebook_artifact_matches` (save_result rows vs expected SQL). Labs that
     need notebooks declare `"requires": ["notebooks"]`.
   - Data checks (runner): `table_has_columns`, `column_type`, `row_count`, `null_count`, `unique`, `value_range`, `query_result_matches`,
     `references` (M9, referential integrity).
     - `query_result_matches` without `actual_sql` grades the task's saved SQL answer and requires `"answer": true` on the task.
   - Need a new type? Add it to the schema, `LabDefinition`, `MetadataChecks` or `worker/ops/lab_ops.py` with tests first (`backend-feature` / `data-execution`). Never embed code.
4. Validate: `php scripts/labs-import.php --dry-run labs/LAB-xxx`. Import with `php scripts/labs-import.php`. A published `code@version` is immutable: bump `version` to change it.
5. Tests: `tests/Labs/LabSolutionsTest.php` picks up every lab automatically. For each lab, an empty attempt must score 0 and `solution/solution.json` must score the maximum.
   - Solution steps: `create_resource`, `delete_resource`, `upload_sample`, `ingest`, `transform`, `answer`,
     `create_container`, `upload_object`, `set_lifecycle`, `create_pipeline`, `run_pipeline`, `create_model` and
     `create_dashboard` (by model name), `create_notebook` and `run_notebook` (LAB-008 runs only when Docker is available).
   - Engine-wide rules have their own tests in `tests/Integration/Labs/LabEngineTest.php`: fabricated client payloads are rejected, each hint penalty applies once, and ownership is enforced.
6. Write the public description in `docs/labs/LAB-xxx.md`, without solutions or check details.

## Rules
- Checks verify actual server state, never client flags.
- Compute expectations with SQL over the setup's bronze tables. Setup datasets cannot be deleted by students, so they are a stable reference. Constants are fine only for data the student loads from a fixed file.
- Expected values must be deterministic. Seed the synthetic data generator.
- Text is in Spanish (i18n keys for UI chrome).
- Cleanup: set `workspace_ttl_days`. Labs must not create resources outside their attempt workspace.
