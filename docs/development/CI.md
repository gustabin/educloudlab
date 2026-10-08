# Continuous integration (M12)

The repository is public on GitHub (`github.com/gustabin/educloudlab`, Apache-2.0). CI runs **locally with Docker**, and the same scripts run unchanged in GitHub Actions (`.github/workflows/ci.yml`) on every push and pull request. GitHub Actions minutes are free for public repositories.

## Local runs

```
bash ci/test.sh                    # PHP 8.3 + MySQL 8.0
bash ci/test.sh php81 mariadb1011  # PHP 8.1 + MariaDB 10.11
bash ci/test.sh php81 mariadb104   # PHP 8.1 + MariaDB 10.4 (XAMPP parity)
CI_STEPS="migrations phpunit" bash ci/test.sh php83 mysql80
```

Docker Desktop must be running. Each run uses its own Compose project and removes its containers and volumes at the end.

| Piece | What it does |
|---|---|
| `ci/Dockerfile` | PHP CLI (8.1 or 8.3, Debian 12) with mysqli/intl/zip, Composer, a Python 3.11 venv with the hash-pinned runner wheels. The code is **copied** in, never mounted: a developer's `.env`, `vendor/` or venv never reach a run. `.dockerignore` excludes secrets and local state. |
| `ci/compose.yml` | A throwaway database server (MySQL 8.0, MariaDB 10.11 or 10.4) in tmpfs, with no ports published, plus the test container. |
| `ci/setup.sh` | Creates the databases and the **same least-privilege users as production** (`ci/db-setup.php`). Writes a CI-only `.env` with random secrets and refuses to overwrite any other `.env`. |
| `ci/run.sh` | Steps: phpcs → phpstan → migrations (up, **down --steps=all**, up) → lab import → PHPUnit (Unit, Integration, Labs, Security) → pytest. |
| `ci/smoke.sh` + `ci/smoke-release.php` | Installs a release archive the way an operator would: `releases/<v>`, the `current` symlink, `shared/.env`. It serves it with the PHP-FPM pool's `open_basedir` and `disable_functions`. Then, over HTTP: health, public pages, register with CSRF, email via the mailer, verify, API token, workspace, lakehouse, CSV upload, profile, ingest, SQL query through the dispatcher and runner, admin health, `/metrics`. |
| `ci/e2e.sh` | On a CI host with Chrome and Docker: builds the notebook image and runs the Sandbox suite, then Playwright E2E against the PHP built-in server. |

## GitHub Actions

`.github/workflows/ci.yml` has three jobs:
- `tests`: the matrix PHP 8.1/8.3 × MySQL 8.0 / MariaDB 10.11, plus 8.1 × 10.4;
- `audit`: `composer audit`, `pip-audit` for both requirement files, `npm audit`;
- `e2e`: the notebook Sandbox suite and Playwright.

**Supply chain:**
- Actions are pinned by commit SHA; images and wheels are pinned by digest and hash.
- The workflow token is read-only (`permissions: contents: read`, `persist-credentials: false`).
- There is no deployment step (master plan §23: no auto-deploy).
- **Pull requests from forks** run with the read-only token and no secrets (`pull_request` trigger). Never switch to `pull_request_target`. Documentation-only changes (`**.md`, `docs/**`) skip the workflow.
- Known exception: CI-only tools (`pytest` from `worker/requirements-dev.txt`, `pip-audit`) are version-pinned but not hash-pinned. They never ship in a release and never run in production.

## Releases

```
php scripts/release.php 1.3.0              # from the tag v1.3.0 (VERSION must say 1.3.0)
php scripts/release.php 1.3.0 --ref=HEAD   # release candidate from a commit
RELEASE_VERSION=1.3.0 docker compose -p educloud-smoke -f ci/compose.yml --profile smoke run --rm smoke
docker compose -p educloud-smoke -f ci/compose.yml down -v
```

The archive contains only committed files minus the `export-ignore` paths in `.gitattributes`: tests, e2e, ci, lab solutions and internal plans. It also contains production Composer dependencies, a `VERSION` and a `RELEASE` file (version, commit, build time), and `SHA256SUMS`. The script refuses to package `.env`, `.git`, tests, dev dependencies or lab solutions.

## Bumping pinned versions

- **Base images:** `docker pull <image>:<tag>`, then copy the `RepoDigests` value into `ci/Dockerfile`, `ci/compose.yml`, `ci/test.sh` and the workflow.
- **Runner wheels:** add the new version's hashes from `https://pypi.org/pypi/<pkg>/<version>/json` (win_amd64 and manylinux x86_64, cp311) to `worker/requirements.txt`.
- **Actions:** `git ls-remote https://github.com/<owner>/<action> refs/tags/<tag>`, and pin the SHA with the tag as a comment.

## What the first Linux CI run found

The CI found these problems, which XAMPP/Windows never showed. They are fixed in M12.

| Finding | Cause | Fix |
|---|---|---|
| Lab grading and transforms randomly failed ("terminó sin respuesta") | DuckDB **segfaults** when the runner's `RLIMIT_AS` cap refuses glibc's per-thread malloc arena reservations | The runner gets `MALLOC_ARENA_MAX=2`, and parser databases open with 1 thread and 64 MB. The runner logs the exit signal when it dies (`runner_no_response`). |
| NDJSON uploads rejected on Debian 12 | libmagic 5.44 reports `application/x-ndjason` (sic) | Added to the upload allowlist |
| Tests failed on MySQL 8 | MySQL 8 normalises JSON key order | Tests compare JSON objects without key order (`assertSameIgnoringKeyOrder`) |
| Tests failed outside `localhost/educloudlab` | Hard-coded development URL and JWT `kid` in tests | Tests read `app.url` and the key ring from config |
| `pip install --require-hashes` failed on Linux | Only the Windows wheel hash was pinned | manylinux hash added (checked against PyPI) |
| Production would not start (every request 500) | The PHP-FPM pool's `open_basedir` did not include `shared/`, where the `.env` symlink points (security gate M12-01) | `shared/` added to the pool. The smoke test now runs with the pool's `open_basedir` and `disable_functions` and the production layout; a negative control confirms it catches this. |
| Admin health returned 500 under the pool | `is_file()` on the venv's `python` (a symlink to `/usr/bin`) raises an `open_basedir` warning, which the app turns into an exception | `is_link()` is checked first |
