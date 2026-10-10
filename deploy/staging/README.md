# Staging runtime

`compose.yaml` is what runs on the staging host. It is not a development stack
(that is `compose.yaml` at the repository root). See
`docs/security/THREAT-MODEL-DEPLOY-PIPELINE.md` for the design.

- **Images** `paxoficloud-php:<git sha>` and `paxoficloud-nginx:<git sha>` are
  built in CI from `docker/production/` after `composer install --no-dev`. They
  are never built on the host or pushed to a registry.
- **Secrets** are read from env files in `/etc/paxoficloud` on the host. The
  deploy pipeline writes them from GitHub Environment secrets:
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
