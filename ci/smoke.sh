#!/usr/bin/env bash
# Release smoke test (M12), inside the CI image: installs build/educloud-lab-<version>.tar.gz the way an operator
# would (releases/<version> + current symlink, venv from the shipped requirements, migrations, lab import) and
# drives it over HTTP with ci/smoke-release.php. Needs the throwaway CI database (CI_DB_HOST/CI_DB_ROOT_PASSWORD).
set -euo pipefail
: "${RELEASE_VERSION:?}"
archive="/release/educloud-lab-$RELEASE_VERSION.tar.gz"
(cd /release && sha256sum -c --ignore-missing SHA256SUMS)

# Same layout as production (docs/deployment/DEPLOYMENT.md), outside /tmp so open_basedir below is meaningful.
root="$HOME/srv/educloud"
mkdir -p "$root/releases" "$root/shared"
tar -xzf "$archive" -C "$root/releases"
ln -sfn "$root/releases/educloud-lab-$RELEASE_VERSION" "$root/current"
cd "$root/current"
echo "== installed $(grep '^version=' RELEASE) ($(find . -type f | wc -l) files)"

python3 -m venv worker/.venv
worker/.venv/bin/pip install -q --disable-pip-version-check --require-hashes --only-binary=:all: -r worker/requirements.txt

export CI_STORAGE="$root/data" CI_PYTHON="$PWD/worker/.venv/bin/python" CI_APP_URL=http://127.0.0.1:8099
bash /app/ci/setup.sh
token=$(php -r 'echo bin2hex(random_bytes(24));')
echo "METRICS_TOKEN=$token" >> .env
# .env lives in shared/ and is linked into the release, as in production.
mv .env "$root/shared/.env" && ln -s "$root/shared/.env" .env

php scripts/check-env.php || true   # informative: Docker is not available in the smoke container
php scripts/migrate.php up
php scripts/labs-import.php

# The web process runs with the PHP-FPM pool's restrictions (deploy/php-fpm/educloud.conf, gate M12-01).
php -d "open_basedir=$root/current/:$root/releases/:$root/shared/:$root/data/:/tmp/"     -d "disable_functions=exec,passthru,shell_exec,system,popen,proc_open,proc_nice,pcntl_exec"     -S 127.0.0.1:8099 -t public public/index.php > /tmp/php-server.log 2>&1 &
trap 'kill %1 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -fsS http://127.0.0.1:8099/api/v1/health >/dev/null 2>&1 && break; sleep 1; done
php /app/ci/smoke-release.php http://127.0.0.1:8099 "$PWD" "$token" || {
  echo "--- app log (events only)"; cat "$root"/data/logs/*.log 2>/dev/null | php -r 'while ($l = fgets(STDIN)) { $r = json_decode($l, true); if (($r["level"] ?? "") !== "info") echo $r["level"], " ", $r["event"], " ", json_encode($r["context"] ?? []), "
"; }' | tail -5
  exit 1
}
