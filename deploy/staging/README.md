# Staging runtime

`compose.yaml` is what runs on the staging host. It is not a development stack
(that is `compose.yaml` at the repository root). See
`docs/security/THREAT-MODEL-DEPLOY-PIPELINE.md` for the design.

- **Images** `paxoficloud-php:<git sha>` and `paxoficloud-nginx:<git sha>` are
  built in CI from `docker/production/` after `composer install --no-dev`. They
  are never built on the host or pushed to a registry.
- **Secrets** are read from env files in `/etc/paxoficloud` on the host. The
  deploy agent generates them on the first release (32 bytes from
  `/dev/urandom` each, files mode 0600) and never prints them; they never pass
  through CI or the logs:
  - `app.env`: `DB_USERNAME`, `DB_PASSWORD`, `REDIS_PASSWORD` (the web tier;
    never the provider-credential KEK)
  - `mysql.env`: `MYSQL_RANDOM_ROOT_PASSWORD=yes`, `MYSQL_DATABASE`,
    `MYSQL_USER`, `MYSQL_PASSWORD`
  - `redis.env`: `REDIS_PASSWORD`
  - `outbox-public.env`: `EMAIL_OUTBOX_PUBLIC_KEY` (web tier: seals queued
    email; the key pair is generated with libsodium in the release image)
  - `outbox-secret.env`: `EMAIL_OUTBOX_SECRET_KEY` (mail worker only)
  - `mail-providers.env`: `POSTMARK_SERVER_TOKEN`, `RESEND_API_KEY`,
    `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD_B64`,
    `EMAIL_PROVIDER`, `EMAIL_RECIPIENT_ALLOWLIST`. These are the only secrets that come from
    CI: the deploy workflow pipes the `staging` environment secrets to the
    `mail-secrets` verb over the tunnel (stdin, never argv or logs).
- **Mail worker:** `mail-worker` (compose profile `mail`) runs
  `bin/paxoficloud mail:work`. It is the only container with the outbox
  secret key and provider tokens, and the only one on the `egress` network.
  The agent enables it only when `mail-providers.env` holds a provider token
  or a complete SMTP relay (host plus password).
  On staging, `EMAIL_RECIPIENT_ALLOWLIST` is the same value as
  `STAGING_ALLOWED_EMAILS`, so mail can only reach people allowed through
  Cloudflare Access (threat model E-9).
- **Web app:** the Nginx image also carries the customer-portal build from
  `frontend/` (built in a pinned Node stage; nothing else from that stage
  ships). Nginx serves it at `/` with its own CSP and security headers, and
  routes only `/health/*` and `/api/*` to PHP.
- **Exposure:** only Nginx is published, on `127.0.0.1:8080`. `cloudflared` on
  the host forwards to it. MySQL and Redis sit on an internal network.

## Smoke test

Builds nothing. It runs the stack from locally built images with throwaway
secrets, then checks that it serves and is hardened:

```sh
APP_VERSION=<tag> deploy/staging/smoke-test.sh
```

CI runs the same script in the "Production images smoke test" job.

## Deploy agent (`host/paxoficloud-deploy`)

Installed by cloud-init as `/usr/local/sbin/paxoficloud-deploy` and bound to the
`deploy` user's key as a forced command. It accepts exactly four verbs:

| Verb | Does |
|---|---|
| `load` | Reads a gzip `docker save` stream from stdin. Keeps only `paxoficloud-php:<sha>` / `paxoficloud-nginx:<sha>`; anything else is removed and the command fails. |
| `release <40-hex sha>` | Generates secrets on first run, starts MySQL/Redis, runs `bin/paxoficloud migrate`, starts the new version and waits for `/health/ready`. On success records `current`/`previous` and prunes older images; on failure restarts the previous version and exits 1. |
| `mail-secrets` | Reads `NAME=value` lines from stdin (max 8 KiB). Accepts only `POSTMARK_SERVER_TOKEN`, `RESEND_API_KEY`, `SMTP_HOST`, `SMTP_PORT` (465/587), `SMTP_USERNAME`, `SMTP_PASSWORD` (stored base64-encoded as `SMTP_PASSWORD_B64`), `EMAIL_PROVIDER` and `EMAIL_RECIPIENT_ALLOWLIST`, each format-checked; any bad line rejects the whole update. Writes `mail-providers.env` atomically (0600) and logs names only. |
| `status` | Prints the current and previous release SHAs, the containers, and a mail summary: worker enabled or disabled, `EMAIL_PROVIDER`, the number of allow-list entries, the names (never values) of the mail settings that are set, and `mail:status` (outbox counts plus the latest delivery problems with their fixed failure reason, e.g. `suppressed-not-allowlisted`). The deploy workflow runs it after every deploy. |

Every run is appended to `/var/log/paxoficloud/deploy.log` (time, verb, SHA,
result). Migrations are forward-only (MIGRATIONS.md), so rolling back code never
needs a schema rollback.

`host/test-deploy-agent.sh` exercises the agent against the real images (bad
input, a release, a broken release that rolls back, pruning). CI runs it after
the smoke test.
