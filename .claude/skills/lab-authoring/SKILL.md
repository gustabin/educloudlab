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
  solution/solution.json # reference actions/SQL used by automated tests (never shown to students)
  data/                  # optional extra synthetic CSV (CC0)
```

## Workflow
1. Define objectives, prerequisites, minutes and difficulty (master plan §28).
2. Write the tasks. Each task has a key, points, hints (with an optional penalty) and `checks[]`.
3. Use **only** registered check types: `workspace_exists`, `resource_exists`, `resource_config_equals`, `dataset_exists`, `table_has_columns`, `column_type`, `row_count`, `null_count`, `unique`, `value_range`, `query_result_matches`, `job_succeeded`. Need a new type? Add it to the check registry with tests first (`backend-feature` / `data-execution`). Never embed code.
4. Validate: `php scripts/labs-import.php --dry-run labs/LAB-xxx`.
5. Tests in `tests/Labs/LabXxxTest.php`:
   - The reference solution yields the max score.
   - An empty attempt yields 0.
   - A fabricated client payload has no effect.
   - Each hint penalty applies once.
6. Write the public description in `docs/labs/LAB-xxx.md`, without solutions or check details.

## Rules
- Checks verify actual server state, never client flags.
- Expected values must be deterministic. Seed the synthetic data generator.
- Text is in Spanish (i18n keys for UI chrome).
- Cleanup: set `workspace_ttl_days`. Labs must not create resources outside their attempt workspace.
