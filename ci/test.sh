#!/usr/bin/env bash
# Runs the CI suite locally in Docker (M12). Examples:
#   ci/test.sh                       PHP 8.3 + MySQL 8.0 (default)
#   ci/test.sh php81 mariadb1011     PHP 8.1 + MariaDB 10.11
#   CI_STEPS="migrations phpunit" ci/test.sh php83 mariadb104
set -euo pipefail
cd "$(dirname "$0")"

case "${1:-php83}" in
  php81) export CI_PHP_IMAGE='php:8.1-cli-bookworm@sha256:482717722f08d9a6c2b4f98c40bfee6d7935bd1a17fef928881ecd5884dfd76e' ;;
  php83) export CI_PHP_IMAGE='php:8.3-cli-bookworm@sha256:c24b55afdf860c874b9ec6267ced132ff52de97d308ab08e6cf41e8596e509f7' ;;
  *) echo "unknown PHP target: $1 (php81|php83)" >&2; exit 2 ;;
esac
case "${2:-mysql80}" in
  mysql80) export CI_DB_IMAGE='mysql:8.0@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b' ;;
  mariadb1011) export CI_DB_IMAGE='mariadb:10.11@sha256:7db29378d4fdab73f8123bbc2b48905c90d1a4b00cf848b028f1e81e623257f2' ;;
  mariadb104) export CI_DB_IMAGE='mariadb:10.4@sha256:22edfe1c78349f8cae88bbb5c2873b7e6c515be767c91c691308ce57445806f3' ;;
  *) echo "unknown database target: $2 (mysql80|mariadb1011|mariadb104)" >&2; exit 2 ;;
esac

project="educloud-ci-${1:-php83}-${2:-mysql80}"
cleanup() { docker compose -p "$project" -f compose.yml down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker compose -p "$project" -f compose.yml build tests
docker compose -p "$project" -f compose.yml run --rm tests
