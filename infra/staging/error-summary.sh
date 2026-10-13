#!/usr/bin/env bash
# Prints a redacted summary of an OpenTofu error log for the PUBLIC Actions log:
# the error headline, the resource it came from, the HTTP status and the
# provider's error code/message. IDs, IPs, hostnames and URLs are removed.
set -uo pipefail
log="${1:?usage: error-summary.sh <tofu log>}"

redact() {
  sed -E \
    -e 's#https?://[^ "]+#<url>#g' \
    -e 's/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/<id>/g' \
    -e 's/[0-9a-fA-F]{32}/<id>/g' \
    -e 's/([0-9]{1,3}\.){3}[0-9]{1,3}/<ip>/g' \
    -e 's/[0-9a-fA-F]{0,4}(:[0-9a-fA-F]{0,4}){2,7}/<ip>/g' \
    -e 's/[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.(com|net|org|io|dev)\b/<host>/g'
}

sed 's/^│ //' "$log" | {
  grep -E '^Error: |^  with [A-Za-z0-9_.]+|[1-5][0-9]{2} (Bad Request|Unauthorized|Forbidden|Not Found|Conflict|Unprocessable Entity|Too Many Requests|Internal Server Error)|"code": ?[0-9]+|"message": ?"' \
    | sed -E 's/^  with ([A-Za-z0-9_.]+).*/  with \1/' \
    | grep -oE '^Error: .{0,160}|^  with [A-Za-z0-9_.]+|[1-5][0-9]{2} [A-Z][A-Za-z ]+|"code": ?[0-9]+|"message": ?"[^"]{0,160}"' \
    | redact | awk '!seen[$0]++' | head -n 40
} || true
