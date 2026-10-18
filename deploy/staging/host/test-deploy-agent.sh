#!/usr/bin/env bash
# Exercises the host deploy agent (paxoficloud-deploy) against real Docker:
# refused commands, input validation, first release, a broken release that
# must roll back, and a second good release with pruning.
#   BASE_TAG=<tag of locally built paxoficloud-php/nginx images> deploy/staging/host/test-deploy-agent.sh
set -euo pipefail

: "${BASE_TAG:?set BASE_TAG to the tag of the locally built images}"
here="$(cd "$(dirname "$0")" && pwd)"
agent="$here/paxoficloud-deploy"
root="$(mktemp -d)"
export PAXOFICLOUD_ROOT="$root"
mkdir -p "$root/opt/paxoficloud"
cp "$here/../compose.yaml" "$root/opt/paxoficloud/compose.yaml"

A=$(printf 'a%.0s' {1..40}); B=$(printf 'b%.0s' {1..40}); C=$(printf 'c%.0s' {1..40})
run() { SSH_ORIGINAL_COMMAND="$1" "$agent"; }
fail() { echo "FAIL: $*" >&2; exit 1; }
current() { cat "$root/var/lib/paxoficloud/current" 2>/dev/null || echo none; }
health() { curl -s --max-time 3 http://127.0.0.1:8080/health/ready || true; }

cleanup() {
  status=$?
  [ "$status" -ne 0 ] && tail -40 "$root/var/log/paxoficloud/deploy.log" 2>/dev/null
  APP_VERSION="$A" PAXOFICLOUD_ENV_DIR="$root/etc/paxoficloud" COMPOSE_PROFILES=mail \
    docker compose -p paxoficloud -f "$root/opt/paxoficloud/compose.yaml" down -v >/dev/null 2>&1 || true
  rm -rf "$root"
  exit "$status"
}
trap cleanup EXIT

# The agent prunes every tag except the current and previous release, so each
# test version is derived from A just before it is used.
for repo in paxoficloud-php paxoficloud-nginx; do docker tag "$repo:$BASE_TAG" "$repo:$A"; done

echo "== refused commands"
for bad in "" "rm -rf /" "release" "release ../../etc/passwd" "release $A extra" "status now" "staff-bootstrap now"; do
  if run "$bad" 2>/dev/null; then fail "accepted: '$bad'"; fi
done

echo "== load validation"
echo "not gzip" | run load 2>/dev/null && fail "accepted a non-gzip stream"
docker tag "paxoficloud-php:$A" "evil-image:latest"
docker save evil-image:latest | gzip | run load 2>/dev/null && fail "accepted an unexpected image"
docker image inspect evil-image:latest >/dev/null 2>&1 && fail "unexpected image was not removed"

echo "== first release"
run "release $A" 2>/dev/null || fail "release A failed"
[ "$(health)" = '{"status":"healthy"}' ] || fail "A not healthy"
[ "$(current)" = "$A" ] || fail "current is not A"
grep -qiE 'password=' "$root/var/log/paxoficloud/deploy.log" && fail "a password reached the deploy log"
[ "$(stat -c %a "$root/etc/paxoficloud/app.env")" = 600 ] || fail "app.env is not 0600"
for f in outbox-public.env outbox-secret.env mail-providers.env web.env identity-keys.env; do
  [ "$(stat -c %a "$root/etc/paxoficloud/$f")" = 600 ] || fail "$f is not 0600"
done
grep -qE '^EMAIL_OUTBOX_PUBLIC_KEY=[A-Za-z0-9+/]{43}=$' "$root/etc/paxoficloud/outbox-public.env" || fail "outbox public key not generated"
grep -qE '^MFA_SECRET_KEYS=k1:[A-Za-z0-9+/]{43}=$' "$root/etc/paxoficloud/identity-keys.env" || fail "MFA key ring not generated"
grep -qE '^MFA_RECOVERY_KEY=[A-Za-z0-9+/]{43}=$' "$root/etc/paxoficloud/identity-keys.env" || fail "MFA recovery key not generated"
mfa_key="$(sed -n 's/^MFA_RECOVERY_KEY=//p' "$root/etc/paxoficloud/identity-keys.env")"
grep -qF -- "$mfa_key" "$root/var/log/paxoficloud/deploy.log" && fail "an MFA key reached the deploy log"
secret_key="$(cut -d= -f2- "$root/etc/paxoficloud/outbox-secret.env")"
grep -qF -- "$secret_key" "$root/var/log/paxoficloud/deploy.log" && fail "the outbox secret key reached the deploy log"
docker ps --format '{{.Names}}' | grep -q mail-worker && fail "mail worker started without a provider token"
status_out="$(run status 2>/dev/null)" || fail "status failed"
grep -qx 'mail worker=disabled provider=default allowlist_entries=0' <<<"$status_out" || fail "status did not report the disabled mail worker"
grep -q '^outbox pending=0 sending=0 sent=0 dead=0$' <<<"$status_out" || fail "status did not report the outbox"

echo "== broken release rolls back"
docker tag "paxoficloud-php:$A" "paxoficloud-php:$B"
printf 'FROM paxoficloud-nginx:%s\nRUN sed -i "s#try_files /index.php =404;#return 503;#" /etc/nginx/conf.d/paxoficloud.conf\n' "$A" \
  | docker build -q -t "paxoficloud-nginx:$B" - >/dev/null
docker save "paxoficloud-php:$B" "paxoficloud-nginx:$B" | gzip | run load 2>/dev/null || fail "load B failed"
if run "release $B" 2>/dev/null; then fail "broken release B reported success"; fi
[ "$(health)" = '{"status":"healthy"}' ] || fail "service not healthy after rollback"
[ "$(current)" = "$A" ] || fail "current changed after a failed release"
grep -q "rolled back to $A" "$root/var/log/paxoficloud/deploy.log" || fail "no rollback recorded"

echo "== mail secrets"
printf 'FOO=bar\n' | run mail-secrets 2>/dev/null && fail "accepted an unknown secret name"
printf 'POSTMARK_SERVER_TOKEN=a b;rm -rf /\n' | run mail-secrets 2>/dev/null && fail "accepted a malformed token"
printf 'SMTP_PORT=25\n' | run mail-secrets 2>/dev/null && fail "accepted SMTP port 25"
printf 'SMTP_HOST=10.0.0.1\n' | run mail-secrets 2>/dev/null && fail "accepted an IP literal as SMTP host"
printf 'SMTP_HOST=mail.example.invalid;id\n' | run mail-secrets 2>/dev/null && fail "accepted an SMTP host with shell characters"
printf 'SMTP_PASSWORD=has space\n' | run mail-secrets 2>/dev/null && fail "accepted an SMTP password with a space"
printf 'SMTP_TLS_NAME=example.invalid;id\n' | run mail-secrets 2>/dev/null && fail "accepted an SMTP TLS name with shell characters"
printf 'EMAIL_PROVIDER=sendmail\n' | run mail-secrets 2>/dev/null && fail "accepted an unknown EMAIL_PROVIDER"
[ -s "$root/etc/paxoficloud/mail-providers.env" ] && fail "a refused update was written"
# SMTP only (staging's real setup), with a password full of characters an
# env file could misread. Postmark/Resend stay empty.
password="p\$a\"s#s'w0rd-$(openssl rand -hex 8)"
printf 'POSTMARK_SERVER_TOKEN=\nRESEND_API_KEY=\nSMTP_HOST=mail.example.invalid\nSMTP_PORT=465\nSMTP_USERNAME=no-reply@example.invalid\nSMTP_PASSWORD=%s\nSMTP_TLS_NAME=example.invalid\nEMAIL_PROVIDER=smtp\nEMAIL_RECIPIENT_ALLOWLIST=@example.invalid\n' "$password" \
  | run mail-secrets 2>/dev/null || fail "mail-secrets refused valid SMTP input"
providers="$root/etc/paxoficloud/mail-providers.env"
[ "$(stat -c %a "$providers")" = 600 ] || fail "mail-providers.env is not 0600"
grep -qxF "SMTP_PASSWORD_B64=$(printf '%s' "$password" | base64 -w0)" "$providers" || fail "SMTP password not stored base64-encoded"
grep -qF -- "$password" "$providers" && fail "SMTP password stored in plain text"
grep -qF -- "$password" "$root/var/log/paxoficloud/deploy.log" && fail "the SMTP password reached the deploy log"

echo "== web secrets"
printf 'EMAIL_PROVIDER=smtp\n' | run web-secrets 2>/dev/null && fail "web-secrets accepted a mail setting"
printf 'TURNSTILE_SECRET_KEY=abc def;id\n' | run web-secrets 2>/dev/null && fail "web-secrets accepted a malformed key"
[ -s "$root/etc/paxoficloud/web.env" ] && fail "a refused web update was written"
turnstile="0x4AAAAAAA$(openssl rand -hex 12)"
printf 'TURNSTILE_SITE_KEY=0x4AAAAAAAsitekey01\nTURNSTILE_SECRET_KEY=%s\n' "$turnstile" | run web-secrets 2>/dev/null || fail "web-secrets refused valid keys"
[ "$(stat -c %a "$root/etc/paxoficloud/web.env")" = 600 ] || fail "web.env is not 0600"
grep -qxF "TURNSTILE_SECRET_KEY=$turnstile" "$root/etc/paxoficloud/web.env" || fail "Turnstile secret not stored"
grep -qF -- "$turnstile" "$root/var/log/paxoficloud/deploy.log" && fail "the Turnstile secret reached the deploy log"

echo "== staff bootstrap (§6.19)"
printf 'EMAIL=ada@example.invalid\nNAME=Ada $(id)\n' | run staff-bootstrap 2>/dev/null && fail "staff-bootstrap accepted a shell-looking name"
printf 'EMAIL=not-an-email\nNAME=Ada Lovelace\n' | run staff-bootstrap 2>/dev/null && fail "staff-bootstrap accepted a malformed email"
printf 'EMAIL=ada@example.invalid\nROLE=super_admin\n' | run staff-bootstrap 2>/dev/null && fail "staff-bootstrap accepted an unknown setting"
printf 'NAME=Ada Lovelace\n' | run staff-bootstrap 2>/dev/null && fail "staff-bootstrap ran without an email"
bootstrap_out="$(printf 'EMAIL=first-admin@example.invalid\nNAME=Ada Lovelace\n' | run staff-bootstrap 2>&1)" || fail "staff-bootstrap refused a valid first admin"
grep -q 'invitation queued' <<<"$bootstrap_out" || fail "staff-bootstrap did not report the invitation"
status=0; printf 'EMAIL=first-admin@example.invalid\nNAME=Ada Lovelace\n' | run staff-bootstrap 2>/dev/null || status=$?
[ "$status" = 2 ] || fail "a second bootstrap for the same address was not refused (exit $status)"
grep -qF 'first-admin@example.invalid' <<<"$bootstrap_out" && fail "staff-bootstrap printed the email address"
grep -qF 'first-admin@example.invalid' "$root/var/log/paxoficloud/deploy.log" && fail "the email address reached the deploy log"

echo "== second release and pruning"
for repo in paxoficloud-php paxoficloud-nginx; do docker tag "$repo:$A" "$repo:$C"; done
run "release $C" 2>/dev/null || fail "release C failed"
[ "$(current)" = "$C" ] || fail "current is not C"
[ "$(cat "$root/var/lib/paxoficloud/previous")" = "$A" ] || fail "previous is not A"
docker image inspect "paxoficloud-nginx:$B" >/dev/null 2>&1 && fail "old image B was not pruned"
docker image inspect "paxoficloud-php:$A" >/dev/null 2>&1 || fail "previous image A was pruned"
worker="$(docker ps --filter name=paxoficloud-mail-worker --format '{{.Names}}' | head -n1)"
[ -n "$worker" ] || fail "mail worker did not start once an SMTP relay was delivered"
[ "$(docker exec "$worker" php -r 'echo base64_decode(getenv("SMTP_PASSWORD_B64"), true);')" = "$password" ] \
  || fail "the worker did not receive the exact SMTP password"

echo "== status shows mail diagnostics without values"
status_out="$(run status 2>/dev/null)" || fail "status failed"
grep -qx 'mail worker=enabled provider=smtp allowlist_entries=1' <<<"$status_out" || fail "status did not report the SMTP mail worker"
grep -q '^mail settings set: .*SMTP_HOST SMTP_PORT SMTP_USERNAME SMTP_PASSWORD_B64 SMTP_TLS_NAME EMAIL_PROVIDER EMAIL_RECIPIENT_ALLOWLIST' <<<"$status_out" \
  || fail "status did not list the configured mail settings"
grep -q 'POSTMARK_SERVER_TOKEN' <<<"$status_out" && fail "status listed an empty setting"
grep -q '^outbox ' <<<"$status_out" || fail "status did not report the outbox"
for leak in "$password" "$(printf '%s' "$password" | base64 -w0)" example.invalid; do
  grep -qF -- "$leak" <<<"$status_out" && fail "status printed a mail setting value"
done

echo "OK: deploy agent refuses bad input, releases, rolls back, prunes, bootstraps the first staff admin without logging the address, wires the mail worker (SMTP) and reports mail status without values"
