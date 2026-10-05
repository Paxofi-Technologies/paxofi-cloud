# Local development

The full stack runs in Docker: PHP-FPM 8.4, Nginx 1.28, MySQL 8.4 and Redis 7.

## First run

```bash
cp .env.example .env            # then change the passwords to your own
export COMPOSER_AUTH='{"github-oauth":{"github.com":"<your read-only token>"}}'
export HOST_UID="$(id -u)" HOST_GID="$(id -g)"   # containers write files as you, never root
docker compose run --rm composer install
docker compose up -d --build --wait
docker compose exec php bin/paxoficloud migrate
curl http://127.0.0.1:8080/health/ready     # {"status":"healthy"}
```

The private Paxofi Core Framework needs a GitHub token with **read-only
Contents access to `paxofi-core-framework`**. Pass it through the environment
as shown. Never put it in `.env`, `composer.json` or `auth.json` inside the
repository.

## Everyday commands

| Task | Command |
|---|---|
| Start / stop | `docker compose up -d --wait` / `docker compose down` |
| Tests (unit) | `docker compose exec php vendor/bin/phpunit --testsuite Unit` |
| Static analysis | `docker compose exec php vendor/bin/phpstan analyse --memory-limit=1G` |
| Migrations | `docker compose exec php bin/paxoficloud migrate` |
| Dependencies | `docker compose run --rm composer install` (or `update`) |
| Logs | `docker compose logs -f php nginx` |
| MySQL shell | `docker compose exec mysql mysql -u"$DB_USERNAME" -p paxoficloud` |
| Reset all data | `docker compose down -v` (deletes the MySQL and Redis volumes) |

Integration tests need a database they may wipe. Run them only against the
local stack:

```bash
docker compose exec -e PAXOFICLOUD_INTEGRATION=1 php vendor/bin/phpunit --testsuite Integration
```

## What the stack enforces

These match production intent (SRS SEC-013), so problems show up locally first.

- **PHP** (`docker/php/conf.d/paxoficloud.ini`): `expose_php`, `display_errors`,
  `allow_url_fopen` and `allow_url_include` are off; errors go to the log;
  assertions are disabled as in production; UTC everywhere.
- **PHP-FPM** never runs as root: it uses your host uid (`HOST_UID`, so
  caches written to the checkout stay yours) or `www-data` by default, in a read-only container with
  all Linux capabilities dropped. Stuck requests are killed after 35 s. The
  runtime image has no Composer, git or compilers; dependencies are installed
  by the separate `composer` service (official `composer:2` image, run on
  demand with `docker compose run --rm composer …`).
- **Nginx** hides its version, serves nothing but `public/index.php`, returns
  404 for dotfiles and rejects bodies over 1 MiB with 413.
- **MySQL** runs strict SQL mode, UTF-8 (`utf8mb4`) and UTC, with a random root
  password that nobody needs. The application uses its own user.
- **Redis** requires a password.
- **Ports** are published on `127.0.0.1` only, so nothing is reachable from
  your network.

## Running PHP on the host instead

Install PHP 8.4 with `pdo_mysql` and `sodium`, start only the data services
with `docker compose up -d mysql redis`, and use the `DB_DSN` /
`REDIS_HOST=127.0.0.1` values from `.env.example`.
