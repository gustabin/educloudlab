#!/usr/bin/env bash
# E2E + notebook sandbox on a Linux CI host (M12, GitHub Actions job "e2e"). Needs on the host: PHP 8.x with
# mysqli/intl/zip, Composer, Python 3.11, Node, Google Chrome, Docker, and a throwaway database server
# (CI_DB_HOST / CI_DB_ROOT_PASSWORD). Never run it on a real install: ci/setup.sh writes a CI-only .env.
set -euo pipefail
cd "$(dirname "$0")/.."

python3.11 -m venv worker/.venv
worker/.venv/bin/pip install --require-hashes --only-binary=:all: -r worker/requirements.txt
composer install --no-interaction --no-progress
npm ci

export CI_STORAGE="$RUNNER_TEMP/educloud-data" CI_PYTHON="$PWD/worker/.venv/bin/python" CI_APP_URL=http://127.0.0.1:8099
bash ci/setup.sh
php scripts/migrate.php up --test
php scripts/migrate.php up
php scripts/labs-import.php

# Notebook isolation suite against the real image (release gate, ADR-010).
php scripts/notebook-image.php build
vendor/bin/phpunit --testsuite Sandbox

# Browser flows against the PHP built-in server (demo notebook mode).
php -S 127.0.0.1:8099 -t public scripts/dev-router.php > "$RUNNER_TEMP/php-server.log" 2>&1 &
trap 'kill %1 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -fsS http://127.0.0.1:8099/api/v1/health >/dev/null && break; sleep 1; done
E2E_BASE_URL=http://127.0.0.1:8099/ npx playwright test
