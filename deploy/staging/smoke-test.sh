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
  owner_password="smoke-$(openssl rand -hex 16)"
  app_password="smoke-$(openssl rand -hex 16)"
  redis_password="smoke-$(openssl rand -hex 16)"
  # Two MySQL users, as on the host (identity threat model §6.22).
  printf 'DB_USERNAME=paxoficloud_app\nDB_PASSWORD=%s\nREDIS_PASSWORD=%s\n' "$app_password" "$redis_password" > "$env_dir/app.env"
  printf 'DB_USERNAME=paxoficloud\nDB_PASSWORD=%s\n' "$owner_password" > "$env_dir/owner.env"
  printf 'MYSQL_RANDOM_ROOT_PASSWORD=yes\nMYSQL_DATABASE=paxoficloud\nMYSQL_USER=paxoficloud\nMYSQL_PASSWORD=%s\nMYSQL_APP_PASSWORD=%s\n' "$owner_password" "$app_password" > "$env_dir/mysql.env"
  printf 'REDIS_PASSWORD=%s\n' "$redis_password" > "$env_dir/redis.env"
  # Email outbox key pair and a fake provider token (nothing is ever sent:
  # the queue is empty and the allow-list matches no real address).
  keys="$(docker run --rm --entrypoint php "paxoficloud-php:$APP_VERSION" -r '$k = sodium_crypto_box_keypair(); echo base64_encode(sodium_crypto_box_publickey($k)), " ", base64_encode(sodium_crypto_box_secretkey($k));')"
  printf 'EMAIL_OUTBOX_PUBLIC_KEY=%s\n' "${keys% *}" > "$env_dir/outbox-public.env"
  printf 'EMAIL_OUTBOX_SECRET_KEY=%s\n' "${keys#* }" > "$env_dir/outbox-secret.env"
  printf 'POSTMARK_SERVER_TOKEN=smoke-%s\nRESEND_API_KEY=\nSMTP_HOST=mail.example.invalid\nSMTP_PORT=587\nSMTP_PASSWORD_B64=%s\nEMAIL_RECIPIENT_ALLOWLIST=@example.invalid\n' \
    "$(openssl rand -hex 8)" "$(printf 'smoke-%s' "$(openssl rand -hex 8)" | base64 -w0)" > "$env_dir/mail-providers.env"
  # Cloudflare's documented always-pass test keys (§6.12); never real ones.
  printf 'TURNSTILE_SITE_KEY=1x00000000000000000000AA\nTURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA\n' > "$env_dir/web.env"
  printf 'MFA_SECRET_KEYS=k1:%s\nMFA_SECRET_ACTIVE_KEY=k1\nMFA_RECOVERY_KEY=%s\n' "$(openssl rand -base64 32)" "$(openssl rand -base64 32)" > "$env_dir/identity-keys.env"
)

echo "== start"
"${compose[@]}" up -d --wait --wait-timeout 240

echo "== migrate and runtime grants (as the schema owner)"
as_owner() { ( set -a; . "$env_dir/owner.env"; set +a; "${compose[@]}" run --rm -T --no-deps -e DB_USERNAME -e DB_PASSWORD php bin/paxoficloud "$@" ); }
"${compose[@]}" exec -T php bin/paxoficloud migrate 2>/dev/null && fail "the runtime user could run migrations"
as_owner migrate
as_owner migrate:status
as_owner db:grants paxoficloud_app

echo "== runtime user is append-only on the audit log (§6.22)"
php_env="$("${compose[@]}" exec -T php env)"
grep -qx 'DB_USERNAME=paxoficloud_app' <<<"$php_env" || fail "php does not connect as the runtime user"
runtime_sql() { "${compose[@]}" exec -T -e "MYSQL_PWD=$(sed -n 's/^MYSQL_APP_PASSWORD=//p' "$env_dir/mysql.env")" mysql mysql -u paxoficloud_app paxoficloud -e "$1" 2>&1; }
runtime_sql 'SELECT COUNT(*) FROM audit_events' >/dev/null || fail "runtime user cannot read the audit log"
# Captured first: grep -q in a pipe would close it early and trip pipefail.
for statement in 'DELETE FROM audit_events' "UPDATE audit_events SET outcome = 'failure'" 'SELECT * FROM schema_migrations' 'CREATE TABLE smoke_ddl (id INT)'; do
  out="$(runtime_sql "$statement" || true)"
  grep -q 'ERROR 1142' <<<"$out" || fail "runtime user was allowed: $statement"
done
owner_secret="$(sed -n 's/^DB_PASSWORD=//p' "$env_dir/owner.env")"
for service in php nginx redis; do
  service_env="$("${compose[@]}" exec -T "$service" env)"
  grep -qF -- "$owner_secret" <<<"$service_env" && fail "the schema owner password is present in $service"
done

echo "== serve"
base=http://127.0.0.1:8080
test "$(curl -s -o /dev/null -w '%{http_code}' "$base/health/live")" = 200 || fail "/health/live is not 200"
test "$(curl -s "$base/health/ready")" = '{"status":"healthy"}' || fail "/health/ready is not healthy"
headers="$(curl -s -D - -o /dev/null "$base/health/live" | tr -d '\r')"
grep -qi '^content-security-policy:' <<<"$headers" || fail "CSP header missing"
grep -qi '^x-powered-by:' <<<"$headers" && fail "X-Powered-By leaked"
grep -qiE '^server: nginx/[0-9]' <<<"$headers" && fail "nginx version leaked"
test "$(curl -s -o /dev/null -w '%{http_code}' "$base/.env")" = 404 || fail "dotfile not blocked"

echo "== web app"
web="$(curl -s -D - "$base/" | tr -d '\r')"
grep -q '^HTTP/1.1 200' <<<"$web" || fail "/ is not 200"
grep -q '<div id="root"></div>' <<<"$web" || fail "/ does not serve the web app"
# Same-origin code only, plus Cloudflare Turnstile's script and iframe (identity threat model §6.12).
grep -qi "^content-security-policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com;" <<<"$web" || fail "web CSP missing"
grep -qi '^x-frame-options: DENY' <<<"$web" || fail "web X-Frame-Options missing"
grep -qi '^cache-control: no-cache' <<<"$web" || fail "index.html must not be cached"
grep -q '<script>' <<<"$web" && fail "inline script in index.html breaks the CSP"
asset="$(grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' <<<"$web" | head -1)"
test -n "$asset" || fail "no script asset referenced"
curl -s -D - -o /dev/null "$base$asset" | tr -d '\r' | grep -qi '^cache-control: public, max-age=31536000, immutable' || fail "hashed assets not cached"
test "$(curl -s -o /dev/null -w '%{http_code}' "$base/assets/missing.js")" = 404 || fail "missing asset must 404"
curl -s "$base/dashboard" | grep -q '<div id="root"></div>' || fail "SPA fallback missing"
test "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$base/")" = 403 || fail "web app accepts POST"
test "$(curl -s -o /dev/null -w '%{http_code}' "$base/api/nope")" = 404 || fail "/api is not routed to PHP"
curl -s "$base/api/nope" | grep -q '"code":"route_not_found"' || fail "/api 404 is not the API's problem+json"
test "$(head -c 2000000 /dev/zero | curl -s -o /dev/null -w '%{http_code}' --data-binary @- "$base/health/live")" = 413 || fail "body size limit not enforced"
echo "== admin host"
# §6.18/§6.19: the staff console and /admin-api answer only on the admin host.
admin=(-H 'Host: cloud-staging-admin.paxofi.com')
admin_page="$(curl -s -D - "${admin[@]}" "$base/enrol" | tr -d '\r')"
grep -q '^HTTP/1.1 200' <<<"$admin_page" || fail "admin host does not serve the console"
grep -q '<title>PaxofiCloud Staff</title>' <<<"$admin_page" || fail "admin host does not serve admin.html"
grep -qi '^content-security-policy: .*frame-ancestors' <<<"$admin_page" || fail "admin console lacks CSP"
grep -qi '^content-security-policy: .*challenges.cloudflare.com' <<<"$admin_page" && fail "admin CSP allows third-party script"
grep -qi '^permissions-policy: .*publickey-credentials-get=(self)' <<<"$admin_page" || fail "admin Permissions-Policy missing"
test "$(curl -s -o /dev/null -w '%{http_code}' "${admin[@]}" "$base/admin-api/v1/auth/session")" = 401 || fail "admin API not reachable on the admin host"
test "$(curl -s -o /dev/null -w '%{http_code}' "${admin[@]}" "$base/api/v1/auth/session")" = 404 || fail "customer API served on the admin host"
test "$(curl -s -o /dev/null -w '%{http_code}' "${admin[@]}" "$base/index.html")" = 404 || fail "customer portal served on the admin host"
test "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' "${admin[@]}" --data '{}' "$base/admin-api/v1/auth/passkey/options")" = 403 || fail "admin write accepted without Origin"
curl -s -X POST -H 'Content-Type: application/json' -H 'Origin: https://cloud-staging-admin.paxofi.com' "${admin[@]}" --data '{}' "$base/admin-api/v1/auth/passkey/options" \
  | grep -q '"rpId":"cloud-staging-admin.paxofi.com"' || fail "passkey sign-in options not issued"
# §6.20: code sign-in is wired (MFA keys, Argon2id, Redis): an unknown address gets the uniform 401, not 503.
curl -s -X POST -H 'Content-Type: application/json' -H 'Origin: https://cloud-staging-admin.paxofi.com' "${admin[@]}" --data '{"email":"nobody@example.test","password":"not a real password at all"}' "$base/admin-api/v1/auth/password" \
  | grep -q '"code":"sign_in_failed"' || fail "staff password sign-in not wired"
curl -s -X POST -H 'Content-Type: application/json' -H 'Origin: https://cloud-staging-admin.paxofi.com' "${admin[@]}" --data '{"token":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"}' "$base/admin-api/v1/enrolment/start" \
  | grep -q '"code":"invitation_invalid"' || fail "staff sign-in setup not wired"
test "$(curl -s -o /dev/null -w '%{http_code}' "$base/admin-api/v1/auth/session")" = 404 || fail "admin API reachable on the customer host"
test "$(curl -s -o /dev/null -w '%{http_code}' "$base/admin.html")" = 404 || fail "admin console served on the customer host"
curl -s "$base/" | grep -q '<title>PaxofiCloud</title>' || fail "customer host does not serve the portal"
"${compose[@]}" exec -T php php -r 'exit(ini_get("expose_php") || ini_get("opcache.validate_timestamps") ? 1 : 0);' || fail "production php.ini not applied"
"${compose[@]}" exec -T php php -r 'exit(extension_loaded("bcmath") && extension_loaded("sodium") ? 0 : 1);' || fail "required PHP extensions missing"

echo "== mail worker"
# The worker boots with its keys and provider config and drains an empty queue.
"${compose[@]}" run --rm -T mail-worker php bin/paxoficloud mail:work --once | grep -q 'Processed 0 email(s).' || fail "mail worker does not start"
# M-02/E-6: the web tier holds the outbox PUBLIC key only, never provider tokens or the secret key.
web_env="$("${compose[@]}" exec -T php env)"
grep -q '^EMAIL_OUTBOX_PUBLIC_KEY=.' <<<"$web_env" || fail "web tier lacks the outbox public key"
grep -qE '^(EMAIL_OUTBOX_SECRET_KEY|POSTMARK_SERVER_TOKEN|RESEND_API_KEY|SMTP_[A-Z_]+)=' <<<"$web_env" && fail "email secrets present in the php container"
# §6.12: the Turnstile secret reaches the web tier and nothing else.
grep -q '^TURNSTILE_SECRET_KEY=.' <<<"$web_env" || fail "web tier lacks the Turnstile secret"
grep -q '^MFA_RECOVERY_KEY=.' <<<"$web_env" || fail "web tier lacks the MFA keys"
"${compose[@]}" run --rm -T mail-worker env | grep -qE '^(TURNSTILE_|MFA_)' && fail "web tier keys present in the mail worker"

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
  done < <(grep -hE "^(DB|MYSQL|REDIS)_PASSWORD=|^EMAIL_OUTBOX_SECRET_KEY=|^POSTMARK_SERVER_TOKEN=|^SMTP_PASSWORD_B64=|^TURNSTILE_SECRET_KEY=|^MFA_(SECRET_KEYS|RECOVERY_KEY)=" "$env_dir"/*.env)
done
# Code inside the image is not writable by the PHP user.
"${compose[@]}" exec -T php sh -c 'test ! -w /app/src && test ! -w /app/vendor' || fail "application code is writable"
# Only Nginx is published, and only on loopback.
published="$(docker ps --filter "label=com.docker.compose.project=$project" --format '{{.Ports}}' | grep -o '[0-9.:]*->' || true)"
test "$published" = "127.0.0.1:8080->" || fail "unexpected published ports: $published"

echo "OK: staging runtime $APP_VERSION serves and is hardened"
