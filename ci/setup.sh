#!/usr/bin/env bash
# Shared CI setup (M12), sourced by ci/run.sh (container) and ci/e2e.sh (GitHub runner):
# creates the databases and least-privilege users on the throwaway CI database server, then writes a CI-only
# .env with random secrets. Never run it on a real install: it overwrites .env.
# Inputs (env): CI_DB_HOST, CI_DB_ROOT_PASSWORD, CI_STORAGE, CI_PYTHON, CI_APP_URL
set -euo pipefail

: "${CI_DB_HOST:?}" "${CI_DB_ROOT_PASSWORD:?}" "${CI_STORAGE:?}" "${CI_PYTHON:?}" "${CI_APP_URL:?}"
if [ -f .env ] && ! grep -q '^# CI-ONLY' .env; then
  echo "refusing to overwrite a non-CI .env" >&2
  exit 1
fi

rand() { php -r 'echo bin2hex(random_bytes(16));'; }
key() { php -r 'echo base64_encode(random_bytes(32));'; }
APP_PASS=$(rand); MIG_PASS=$(rand); TEST_PASS=$(rand)

php "$(dirname "${BASH_SOURCE[0]}")/db-setup.php" "$CI_DB_HOST" "$CI_DB_ROOT_PASSWORD" "$APP_PASS" "$MIG_PASS" "$TEST_PASS"
mkdir -p "$CI_STORAGE"

cat > .env <<EOF
# CI-ONLY (written by ci/setup.sh; random throwaway secrets)
APP_ENV=testing
APP_DEBUG=false
APP_URL=$CI_APP_URL
APP_LOCALE=es
DB_HOST=$CI_DB_HOST
DB_PORT=3306
DB_NAME=educloud
DB_USER=educloud_app
DB_PASS=$APP_PASS
DB_MIGRATOR_USER=educloud_migrator
DB_MIGRATOR_PASS=$MIG_PASS
DB_TEST_NAME=educloud_test
DB_TEST_USER=educloud_test
DB_TEST_PASS=$TEST_PASS
STORAGE_PATH=$CI_STORAGE
WORKER_PYTHON=$CI_PYTHON
JWT_KEYS=ci1:$(key)
JWT_ISSUER=$CI_APP_URL
JWT_AUDIENCE=educloud-api
MAIL_DRIVER=file
MAIL_FROM=no-reply@educloud.local
APP_HASH_KEY=$(key)
NOTEBOOKS_MODE=${CI_NOTEBOOKS_MODE:-demo}
EOF
