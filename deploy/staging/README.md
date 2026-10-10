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
`deploy` user's key as a forced command. It accepts exactly three verbs:

| Verb | Does |
|---|---|
| `load` | Reads a gzip `docker save` stream from stdin. Keeps only `paxoficloud-php:<sha>` / `paxoficloud-nginx:<sha>`; anything else is removed and the command fails. |
| `release <40-hex sha>` | Generates secrets on first run, starts MySQL/Redis, runs `bin/paxoficloud migrate`, starts the new version and waits for `/health/ready`. On success records `current`/`previous` and prunes older images; on failure restarts the previous version and exits 1. |
| `status` | Prints the current and previous release SHAs. |

Every run is appended to `/var/log/paxoficloud/deploy.log` (time, verb, SHA,
result). Migrations are forward-only (MIGRATIONS.md), so rolling back code never
needs a schema rollback.

`host/test-deploy-agent.sh` exercises the agent against the real images (bad
input, a release, a broken release that rolls back, pruning). CI runs it after
the smoke test.
