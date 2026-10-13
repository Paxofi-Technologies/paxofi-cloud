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
  APP_VERSION="$A" PAXOFICLOUD_ENV_DIR="$root/etc/paxoficloud" \
    docker compose -p paxoficloud -f "$root/opt/paxoficloud/compose.yaml" down -v >/dev/null 2>&1 || true
  rm -rf "$root"
  exit "$status"
}
trap cleanup EXIT

# The agent prunes every tag except the current and previous release, so each
# test version is derived from A just before it is used.
for repo in paxoficloud-php paxoficloud-nginx; do docker tag "$repo:$BASE_TAG" "$repo:$A"; done

echo "== refused commands"
for bad in "" "rm -rf /" "release" "release ../../etc/passwd" "release $A extra" "status now"; do
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

echo "== broken release rolls back"
docker tag "paxoficloud-php:$A" "paxoficloud-php:$B"
printf 'FROM paxoficloud-nginx:%s\nRUN sed -i "s#try_files /index.php =404;#return 503;#" /etc/nginx/conf.d/paxoficloud.conf\n' "$A" \
  | docker build -q -t "paxoficloud-nginx:$B" - >/dev/null
docker save "paxoficloud-php:$B" "paxoficloud-nginx:$B" | gzip | run load 2>/dev/null || fail "load B failed"
if run "release $B" 2>/dev/null; then fail "broken release B reported success"; fi
[ "$(health)" = '{"status":"healthy"}' ] || fail "service not healthy after rollback"
[ "$(current)" = "$A" ] || fail "current changed after a failed release"
grep -q "rolled back to $A" "$root/var/log/paxoficloud/deploy.log" || fail "no rollback recorded"

echo "== second release and pruning"
for repo in paxoficloud-php paxoficloud-nginx; do docker tag "$repo:$A" "$repo:$C"; done
run "release $C" 2>/dev/null || fail "release C failed"
[ "$(current)" = "$C" ] || fail "current is not C"
[ "$(cat "$root/var/lib/paxoficloud/previous")" = "$A" ] || fail "previous is not A"
docker image inspect "paxoficloud-nginx:$B" >/dev/null 2>&1 && fail "old image B was not pruned"
docker image inspect "paxoficloud-php:$A" >/dev/null 2>&1 || fail "previous image A was pruned"

echo "OK: deploy agent refuses bad input, releases, rolls back and prunes"
