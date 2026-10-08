#!/usr/bin/env bash
# CI test run inside ci/Dockerfile (M12). Used unchanged by ci/test.sh and the GitHub Actions matrix.
# Needs CI_DB_HOST and CI_DB_ROOT_PASSWORD (throwaway CI database server only). Runs every check except the
# Docker notebook sandbox and E2E (see ci/e2e.sh).
set -euo pipefail
cd /app

export CI_STORAGE=/tmp/educloud-data CI_PYTHON=/opt/venv/bin/python CI_APP_URL=http://127.0.0.1:8099
CI_STEPS="${CI_STEPS:-lint stan migrations phpunit pytest}"
echo "== PHP $(php -r 'echo PHP_VERSION;') · Python $(/opt/venv/bin/python -V | cut -d' ' -f2)"
bash ci/setup.sh

for step in $CI_STEPS; do
  echo "== $step"
  case "$step" in
    lint) vendor/bin/phpcs -q ;;
    stan) vendor/bin/phpstan analyse --memory-limit=1G --no-progress ;;
    migrations)
      # Every migration must apply, roll back completely and re-apply (portability + down scripts).
      php scripts/migrate.php up --test
      php scripts/migrate.php down --test --steps=all
      php scripts/migrate.php up --test
      php scripts/migrate.php up
      php scripts/labs-import.php ;;
    phpunit) vendor/bin/phpunit --testsuite Unit,Integration,Labs,Security ;;
    pytest) /opt/venv/bin/python -m pytest worker/tests -q ;;
    *) echo "unknown step: $step" >&2; exit 2 ;;
  esac
done
echo "== CI OK"
