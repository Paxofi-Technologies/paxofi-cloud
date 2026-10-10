#!/usr/bin/env bash
# Starts the staging runtime (deploy/staging/compose.yaml) from locally built
# images with throwaway secrets, then checks that it serves and that the
# hardening in docs/security/THREAT-MODEL-DEPLOY-PIPELINE.md holds.
#   APP_VERSION=<tag> deploy/staging/smoke-test.sh
set -euo pipefail

: "${APP_VERSION:?set APP_VERSION to the image tag to test}"
here="$(cd "$(dirname "$0")" && pwd)"
project=paxoficloud-smoke
env_dir="$(mktemp -d)"
export APP_VERSION PAXOFICLOUD_ENV_DIR="$env_dir"
compose=(docker compose -f "$here/compose.yaml" -p "$project")

cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then "${compose[@]}" ps || true; "${compose[@]}" logs --no-color --tail 50 || true; fi
  "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$env_dir"
  exit "$status"
}
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

# Throwaway secrets: random per run, written 0600, never printed.
(
  umask 077
  db_password="smoke-$(openssl rand -hex 16)"
  redis_password="smoke-$(openssl rand -hex 16)"
  printf 'DB_USERNAME=paxoficloud\nDB_PASSWORD=%s\nREDIS_PASSWORD=%s\n' "$db_password" "$redis_password" > "$env_dir/app.env"
  printf 'MYSQL_RANDOM_ROOT_PASSWORD=yes\nMYSQL_DATABASE=paxoficloud\nMYSQL_USER=paxoficloud\nMYSQL_PASSWORD=%s\n' "$db_password" > "$env_dir/mysql.env"
  printf 'REDIS_PASSWORD=%s\n' "$redis_password" > "$env_dir/redis.env"
)

echo "== start"
"${compose[@]}" up -d --wait --wait-timeout 240

echo "== migrate"
"${compose[@]}" exec -T php bin/paxoficloud migrate
"${compose[@]}" exec -T php bin/paxoficloud migrate:status

echo "== serve"
base=http://127.0.0.1:8080
test "$(curl -s -o /dev/null -w '%{http_code}' "$base/health/live")" = 200 || fail "/health/live is not 200"
test "$(curl -s "$base/health/ready")" = '{"status":"healthy"}' || fail "/health/ready is not healthy"
headers="$(curl -s -D - -o /dev/null "$base/health/live" | tr -d '\r')"
grep -qi '^content-security-policy:' <<<"$headers" || fail "CSP header missing"
grep -qi '^x-powered-by:' <<<"$headers" && fail "X-Powered-By leaked"
grep -qiE '^server: nginx/[0-9]' <<<"$headers" && fail "nginx version leaked"
test "$(curl -s -o /dev/null -w '%{http_code}' "$base/.env")" = 404 || fail "dotfile not blocked"
test "$(head -c 2000000 /dev/zero | curl -s -o /dev/null -w '%{http_code}' --data-binary @- "$base/health/live")" = 413 || fail "body size limit not enforced"
"${compose[@]}" exec -T php php -r 'exit(ini_get("expose_php") || ini_get("opcache.validate_timestamps") ? 1 : 0);' || fail "production php.ini not applied"

echo "== hardening"
for service in php nginx redis; do
  id="$("${compose[@]}" ps -q "$service")"
  test "$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$id")" = true || fail "$service root filesystem is writable"
  docker inspect -f '{{json .HostConfig.SecurityOpt}}' "$id" | grep -q 'no-new-privileges:true' || fail "$service lacks no-new-privileges"
done
test "$("${compose[@]}" exec -T php id -u)" != 0 || fail "php runs as root"
test "$("${compose[@]}" exec -T redis id -u)" != 0 || fail "redis runs as root"
# D-07: the web tier never holds the provider-credential KEK.
"${compose[@]}" exec -T php env | grep -q '^PROVIDER_CREDENTIAL' && fail "KEK present in the php container"
# P-5/D-04: secret values live only in env files. They must never appear in a
# container's command, entrypoint or labels (visible to `docker inspect`).
for service in php nginx mysql redis; do
  id="$("${compose[@]}" ps -q "$service")"
  config="$(docker inspect -f '{{json .Config.Cmd}}{{json .Config.Entrypoint}}{{json .Config.Labels}}' "$id")"
  test -n "$config" || fail "cannot inspect $service"
  while IFS='=' read -r _ value; do
    [ -n "$value" ] || continue
    grep -qF -- "$value" <<<"$config" && fail "a secret value appears in the $service container definition"
  done < <(grep -hE "^(DB|MYSQL|REDIS)_PASSWORD=" "$env_dir"/*.env)
done
# Code inside the image is not writable by the PHP user.
"${compose[@]}" exec -T php sh -c 'test ! -w /app/src && test ! -w /app/vendor' || fail "application code is writable"
# Only Nginx is published, and only on loopback.
published="$(docker ps --filter "label=com.docker.compose.project=$project" --format '{{.Ports}}' | grep -o '[0-9.:]*->' || true)"
test "$published" = "127.0.0.1:8080->" || fail "unexpected published ports: $published"

echo "OK: staging runtime $APP_VERSION serves and is hardened"
