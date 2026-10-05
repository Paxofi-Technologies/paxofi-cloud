# PCF Developer Reference (for PaxofiCloud)

Reference for building PaxofiCloud on the **Paxofi Core Framework (PCF) v1.1.0** (`paxofi-technologies/paxofi-core-framework`, namespace `Paxofi\Core\`). Written from the v1.1.0 source (commit `cd6a1f5`), not from the specification, so it describes what the code does today.

- **Provided by PCF**: use it; do not rebuild it in PaxofiCloud.
- **Gap**: PCF has no implementation. PaxofiCloud builds it in its own infrastructure layer (`PaxofiCloud\Infrastructure\…`) behind a PaxofiCloud interface, or proposes a PCF change. Section 14 lists every gap and where it belongs.

PCF is deliberately small (≈4,500 lines in `src/`). It is a kernel, a container, an HTTP pipeline, PDO and Redis plumbing, a queue and worker, retry and idempotency primitives, configuration and observability. It is **not** a full-stack framework. Sessions, CSRF, validation, encryption, rate limiting, migrations, mail and authorisation are not in v1.1.0.

---

## 1. Version and compatibility

| | |
|---|---|
| Release | v1.1.0 (5 Sept 2026). v1.0.0 plus the Configuration & Environment foundation; additive, no breaking changes |
| PHP | ≥ 8.4 (8.5 recommended). `Paxofi\Core\Bootstrap` refuses to boot below 8.4 |
| Runtime deps | `ext-json`, `predis/predis` ^3. cURL is needed for `NativeHttpIntegrationClient` |
| Verified | 179 tests (Redis 7 integration green; MySQL acceptance tests skip without a DB); PHPStan clean |

## 2. Namespace map

| Namespace | What it contains |
|---|---|
| `Paxofi\Core\Contracts` | All interfaces (and two value objects: `IntegrationResponse`, `QueueDelivery`). **Type-hint against these.** |
| `Paxofi\Core` | `Application` (lifecycle + container holder), `Bootstrap` (runtime check + boot → `Kernel\Kernel`) |
| `…\Bootstrap` | `ApplicationFactory`, `Runtime` |
| `…\Kernel` | `Kernel` (non-HTTP run/terminate), `LifecycleState` enum |
| `…\Container` | `Container` (DI with autowiring) |
| `…\Configuration` | `EnvLoader`, `Environment`, `FileLoader`, `Cache`, `Bootstrap`, `Repository`, `Validator`, `Provider` |
| `…\Http` | `Kernel` (HTTP), `Router`, `Route`, `Request`, `Response`, `MiddlewarePipeline`, `ControllerDispatcher`, `ExceptionHandler`, `LoggingExceptionHandler`, `HttpException` |
| `…\Persistence` | `PdoConnection`, `PdoRepository`, `TransactionManager`, `Exception\PersistenceException` |
| `…\Infrastructure` | `Provider` (wires PDO, Redis, cache, queues, retry, logging, metrics), `DriverRegistry`, `Redis\*`, `Database\PdoConnection` |
| `…\Queue` | `InMemoryQueue`, `RedisQueue`, `RedisReliableQueue`, `NativeQueuePayloadSerializer`, `Worker` |
| `…\Resilience` | `FixedRetryPolicy`, `ExponentialBackoffRetryPolicy`, `RetryingResiliencePolicy`, `IdempotentOperation`, `NativeSleeper` |
| `…\Integration` | `NativeHttpIntegrationClient`, `InMemoryIntegrationClient`, `IntegrationClientRegistry` |
| `…\Security` | `NativePasswordHasher`, `InMemoryAuthenticator` |
| `…\Cache` | `RedisCache`, `InMemoryCache` |
| `…\Events` | `InMemoryEventDispatcher` |
| `…\Logging` / `…\Observability` | `StreamLogger`, `NullLogger`; `StreamMetrics`, `InMemoryMetrics`, `HealthRegistry`, `HealthResult`, `HealthStatus` |
| `…\CLI` | `CommandRegistry`, `CommandRunner` |
| `…\Application` | `Context`, `Scope`, `ScopeManager`, `ApplicationService`, `ApplicationLifecycle` |

## 3. Application lifecycle and bootstrap

`Paxofi\Core\Application` is a strict state machine (`LifecycleState`):

```
CREATED → BOOTSTRAPPING → BOOTSTRAPPED → RUNNING → TERMINATING → TERMINATED
```

- `registerProvider()` and `bootProviders()` are only legal while **BOOTSTRAPPING**; otherwise `LogicException`.
- `registerProvider()` calls `$provider->register($app)` immediately. `bootProviders()` then calls every `boot()` in registration order.
- An invalid transition throws `LogicException` ("Invalid lifecycle transition from … to …").

A provider implements `Contracts\Provider`:

```php
use Paxofi\Core\Application;
use Paxofi\Core\Contracts\Provider;

final class CatalogueProvider implements Provider
{
    public function register(Application $application): void
    {
        $c = $application->container();
        $c->singleton(CatalogueReader::class, fn ($c) => new PdoCatalogueReader($c->get(Repository::class)));
    }

    public function boot(Application $application): void {}
}
```

Three ways to boot:

| API | Returns | Use |
|---|---|---|
| `(new Paxofi\Core\Bootstrap())->boot($providers)` | `Kernel\Kernel` | Validates PHP ≥ 8.4 + ext-json; wraps any provider failure in `LogicException('PCF bootstrap failed before runtime activation.')` |
| `(new Bootstrap\ApplicationFactory())->create($container, $providers)` | `Application` (BOOTSTRAPPED) | When you need your own container instance |
| `Bootstrap\Runtime::boot(?$app, $providers)` | `Application` | Same as the factory; reuses a fresh app's container |

**Provider order matters.** `Infrastructure\Provider` only registers a default (empty) `Configuration` if none is bound. Register `Configuration\Provider` **first**.

## 4. Configuration and environment (new in v1.1.0)

Pipeline: `.env` / process env → `EnvLoader` → `Environment` → `config/*.php` → `FileLoader` (+ optional `Cache`) → `Repository` → container (`Contracts\Configuration`).

```php
use Paxofi\Core\Configuration\{Bootstrap as ConfigBootstrap, Provider as ConfigProvider, Validator};

$config = ConfigBootstrap::fromDirectory(baseDirectory: dirname(__DIR__), useCache: $isProduction);
(new Validator())->required($config->environment(), ['APP_KEY', 'DB_DSN', 'DB_USERNAME', 'DB_PASSWORD']);

$providers = [ConfigProvider::fromBootstrap($config), new \Paxofi\Core\Infrastructure\Provider(), /* app providers */];
```

- **`EnvLoader::load($path, $required = false)`** parses `KEY=value`, `export KEY=…`, `#` comments, single/double quotes (`stripcslashes` inside double quotes). Keys must match `^[A-Z][A-Z0-9_]*$`, otherwise `InvalidArgumentException`. **Process environment variables override `.env`.** It never writes to `putenv`/`$_ENV`.
- **`Environment`** is immutable. Methods: `name()` (`development|testing|staging|production`; aliases `dev|test|stage|prod`; anything else throws), `is{Development,Testing,Staging,Production}()`, `get(key, ?default): ?string`, `require(key): string`, `boolean(key, default)` (accepts 1/0/true/false/yes/no/on/off, otherwise throws), `integer(key, ?default)`, `has()`, `all()`.
- **`config/*.php`** returns an array or `static fn (Environment $env): array`. The file name becomes the top-level key (`config/database.php` → `database.*`). Files load in sorted order.
- **`Repository::get('a.b.c', $default)`** uses dot notation; an empty key throws.
- **`Cache`** writes `storage/cache/config.php` atomically (temp file + `rename`) and reads it with `require`. Keep it outside the web root and never commit it. Rebuild on every deploy.

**Keys read by `Infrastructure\Provider`.** These must exist in PaxofiCloud's `config/`. PCF's own sample `config/database.php` and `config/cache.php` do **not** produce them (see §15).

| Key | Default if missing | Notes |
|---|---|---|
| `database.dsn` | `mysql:host=127.0.0.1;dbname=pcf;charset=utf8mb4` | Must be a non-empty string |
| `database.username` / `database.password` | `pcf` / `''` | |
| `redis.host` / `redis.port` / `redis.database` / `redis.password` | `127.0.0.1` / `6379` / `0` / none | Values must be **ints**, not numeric strings → use `$env->integer()` |
| `queue.name` / `queue.reliable_name` / `queue.visibility_timeout` | `pcf:default` / `pcf:reliable` / `60` | |
| `queue.allowed_classes` | `[]` | Job classes allowed through (de)serialisation (§9) |
| `idempotency.prefix` | `pcf:idempotency:` | |
| `resilience.retry.{attempts,initial_delay_ms,maximum_delay_ms,jitter_ratio,retryable_exceptions,maximum_elapsed_ms}` | `1, 100, 5000, 0.0, ['Throwable'], null` | |
| `observability.log.stream` / `observability.metrics.stream` | `php://stderr` | `fopen($dest, 'ab')` |

## 5. Dependency injection container

`Container\Container` implements `Contracts\Container`.

| Method | Lifetime |
|---|---|
| `bind($id, Closure $factory)` | Transient: a new object on every `get()` |
| `singleton($id, Closure $factory)` | One per container |
| `scoped($id, Closure $factory)` | One per scope. The HTTP kernel opens a scope per request; outside a scope `get()` throws |
| `instance($id, object)` | Pre-built singleton |
| `get($id)` / `has($id)` | `has()` is true for any existing class name |

- Factories receive the container: `fn (Contracts\Container $c) => new X($c->get(Y::class))`. A factory must return an object.
- **Autowiring.** An unbound concrete class is built by reflection, and every constructor parameter must be a class or interface type. **Scalar parameters (`string`, `int`, `array`…) need an explicit binding.** Circular dependencies throw with the full chain.
- Re-binding an id clears its cached instance.
- Bind interfaces to implementations in providers. Never call `$container->get()` from domain or application code (no service locator).

## 6. HTTP

### 6.1 Request and response (immutable)

```php
$request = new Paxofi\Core\Http\Request(
    method: 'POST', uri: '/v1/carts', headers: ['Content-Type' => 'application/json'],
    body: $rawBody, query: $_GET, attributes: [],
);
```

- Header names are lower-cased; `header('X-Foo')` joins multiple values with `, `.
- `withAttribute()` returns a new request. The framework reserves `_request_id` and `_route_parameters`.
- `Response(int $status = 200, array $headers = [], string $body = '')`, with `withStatus/withHeader/withBody`.
- **Gap:** no `Request::fromGlobals()` and no response emitter. PaxofiCloud's `public/index.php` builds the request from `$_SERVER`/`php://input` and emits status, headers and body itself (§16). There is no JSON helper, no cookie API and no uploaded-file abstraction.

### 6.2 Routing

```php
$router = new Paxofi\Core\Http\Router();
$router->get('/health', fn (HttpRequest $r) => new Response(200, [], 'ok'));
$router->controller('GET', '/v1/products/{productId}', ShowProductController::class, new ControllerDispatcher($container));
```

- The pattern syntax is `{name}`, matching `[^/]+`. A trailing slash is optional. Matching is linear in registration order (first match wins).
- Parameters are read with `$request->attributes()['_route_parameters']['productId']`.
- An unknown path returns `404 Not Found`. A known path with the wrong method returns `405` with an `Allow` header.
- There are no route groups, names, per-route middleware, constraints or HEAD/OPTIONS handling. Apply per-route concerns (auth, tenancy) as middleware that checks the path, or inside the controller.

### 6.3 Controllers

A controller implements `Contracts\Controller` (`__invoke(HttpRequest): HttpResponse`). `ControllerDispatcher` resolves it from the container (so constructor injection works) and throws `LogicException` if the class does not implement the contract.

### 6.4 Middleware

```php
final class RequireJson implements HttpMiddleware
{
    public function process(HttpRequest $request, HttpHandler $next): HttpResponse
    {
        if ($request->method() !== 'GET' && !str_starts_with((string) $request->header('content-type'), 'application/json')) {
            throw new HttpException(415);
        }
        return $next->handle($request);
    }
}
```

Middleware runs in list order around the router. Short-circuit by returning a response without calling `$next`.

### 6.5 HTTP Kernel

`new Http\Kernel(Application $app, Router $router, array $middleware = [], ?ExceptionHandler $handler = null)`. `handle($request)`:

1. Moves the app from BOOTSTRAPPED to RUNNING on the first request.
2. **Enters a container scope** (scoped bindings are per request) and always leaves it.
3. Uses `X-Request-Id` from the client or generates 32 hex chars, and stores it as `_request_id`.
4. Runs middleware → router. **Any `Throwable` goes to the exception handler.**
5. Adds `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` and `X-Request-Id`.

**Security notes:** the client-supplied `X-Request-Id` is echoed back unvalidated (it is a log-injection vector, so validate or replace it in middleware). HSTS, CSP, `Permissions-Policy`, `Cache-Control: no-store` and CORS are not set; PaxofiCloud adds them in middleware.

### 6.6 Errors

- Throw `Http\HttpException(int $status 400–599, $message)` for client errors.
- `Http\ExceptionHandler(bool $debug = false)` returns `text/plain` responses with **generic messages** unless `$debug` is true, in which case it returns the exception message. Never enable debug in staging or production.
- `LoggingExceptionHandler` logs only the exception **class**, with no message or trace. PaxofiCloud needs its own JSON problem-details handler (RFC 9457) that logs class, message, trace and request ID server-side and returns a stable error code to the client.

## 7. Persistence

Bound by `Infrastructure\Provider`:

| Contract | Implementation | API |
|---|---|---|
| `\PDO` | native | `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, **`EMULATE_PREPARES=false`** |
| `Contracts\Connection` | `Persistence\PdoConnection` | `beginTransaction()`, `commit()`, `rollBack()`, `execute($sql, $params): int` (rows affected). PDO errors become `PersistenceException` |
| `Contracts\Repository` | `Persistence\PdoRepository` | `fetchAll($sql, $params): list<array<string,mixed>>` |
| `Contracts\TransactionManager` | `Persistence\TransactionManager` | `transaction(callable): mixed`: commit, or roll back and rethrow |

```php
$id = $tx->transaction(function () use ($conn, $repo, $order): string {
    $conn->execute('INSERT INTO orders (id, tenant_id, status, total_minor, currency) VALUES (:id, :t, :s, :m, :c)', [...]);
    return $order->id;
});
```

- Always use named parameters; never interpolate SQL.
- **Gaps:** no query builder, no `fetchOne`, no `lastInsertId` (use application-generated ULID/UUIDv7 IDs, which is better for multi-tenant systems anyway), **no migrations or seeders**, no nested transactions or savepoints (a nested `transaction()` call throws from PDO), no read replicas, no row locking helpers (write `SELECT … FOR UPDATE` in SQL inside `transaction()`).
- There are two `PdoConnection` classes. Use `Persistence\PdoConnection`, which wraps errors. `Infrastructure\Database\PdoConnection` is a raw driver and does not wrap exceptions.

## 8. Redis, cache and idempotency

- `Contracts\RedisClient` (`PredisRedisClient`) provides `get/set/setEx/setIfAbsentEx/expire/delete/exists/pushLeft/popRight/moveRightToLeft/remove/evaluate(lua)/length`. **The connection is plain `tcp`, with no TLS option.** Run Redis on a private network or loopback only.
- `Contracts\Cache` (`RedisCache`, prefix `pcf:`) stores values JSON-encoded as `{"value": …}`. Any JSON-encodable value works; objects come back as arrays.
- `Contracts\IdempotencyStore` (`PredisIdempotencyStore`) offers `claim($key, $ttl): bool`: atomic `SET NX EX` on `prefix + sha256(key)`.
- `Resilience\IdempotentOperation($store, $ttl)->execute($key, $op): bool` claims and then runs the operation. **The semantics are at-most-once:** if `$op` throws after the claim, the key stays claimed until the TTL expires and a retry is silently skipped. For money and provisioning, PaxofiCloud needs a durable idempotency table in MySQL that records request hash, status and response, with states `in_progress → completed | failed`. Use Redis only as a fast lock.

## 9. Queues and workers

| Class | Semantics |
|---|---|
| `RedisQueue` | `LPUSH`/`RPOP`. **At-most-once**: a job is lost if the worker dies mid-job. Not for provisioning |
| `RedisReliableQueue` | Lua-atomic reserve into `:processing` with a lease in a `:leases` sorted set. `acknowledge`/`release` take a receipt; `recoverExpired()` requeues expired leases. **At-least-once**, so handlers must be idempotent |
| `NativeQueuePayloadSerializer(array $allowedClasses)` | JSON envelope `{version, class, payload: base64(serialize(job))}`; `unserialize` with an `allowed_classes` allowlist. **Every class inside the job graph (value objects too) must be allowlisted**, otherwise it becomes `__PHP_Incomplete_Class` and fails. Keep jobs to scalars and IDs |
| `Worker($queue, ?$resilience, ?$deadLetterQueue, ?$metrics)` | `run(int $limit, callable $handler): int`. Recovers expired leases, reserves, runs the handler inside the resilience policy, then acks. On failure: with a DLQ it pushes there and acks; **without a DLQ it releases the job and rethrows** |

```php
$worker = new Worker($reliableQueue, $container->get(ResiliencePolicy::class), $deadLetterQueue, $metrics);
$worker->run(50, fn (object $job) => $handlers->handle($job));
```

**Gaps for the provisioning engine (CLAUDE.md §6):** no maximum delivery count (without a DLQ a poison job loops forever, which violates the "no unbounded retries" invariant), no delayed or scheduled jobs (backoff happens via an in-process `usleep`, blocking the worker), no job priority, no cron scheduler, and no persisted job history. PaxofiCloud's provisioning engine therefore keeps **job state in MySQL** (`provisioning_jobs`: status, attempt, next_attempt_at, last_error, idempotency_key, compensation state). It uses the Redis reliable queue only as the wake-up and dispatch channel, always configures a DLQ, and a scheduler command enqueues due retries.

## 10. Resilience

- `FixedRetryPolicy($attempts)`: no delay.
- `ExponentialBackoffRetryPolicy($attempts, $initialMs, $maxMs, $retryable = [Throwable::class], $jitter = 0.0)`: delay = `min(initial·2^(n-1), max)` ± jitter. **`$retryable` matches the exact class name, not subclasses** (only `Throwable` matches everything). List concrete exception classes.
- `RetryingResiliencePolicy($retryPolicy, ?$sleeper, ?$maximumElapsedMs)->execute(callable)`: retries in-process with a total time budget. Always set `maximumElapsedMs` on HTTP paths.
- **No circuit breaker** is provided. Add one per provider adapter in PaxofiCloud (state in Redis).

## 11. Outbound integrations

`NativeHttpIntegrationClient($name = 'http', $timeout = 30, $connectTimeout = 10)->send($method, $absoluteUrl, $headers, ?$body): IntegrationResponse{statusCode, headers, body}`

- Redirects are not followed. Timeouts are enforced. CR/LF in headers is rejected. cURL's default TLS verification stays on.
- It is transport only: no auth, retries or JSON handling (by design).
- **Gaps and risks (provider adapters must cover):** no protocol allowlist (restrict to `https` and validate hosts against a per-provider allowlist, which prevents SSRF if a URL is ever data-driven), **no response size cap**, no request signing, and no structured logging of the request ID or latency. Wrap the client in a `PaxofiCloud\Infrastructure\Http\ProviderHttpClient` that adds these, and redact secrets in logs.
- `IntegrationClientRegistry` registers clients by unique name; `InMemoryIntegrationClient` is a test double that returns canned responses keyed by `METHOD:uri`.

## 12. Security primitives

| Provided | Behaviour | PaxofiCloud action |
|---|---|---|
| `Contracts\PasswordHasher` / `NativePasswordHasher` | `password_hash(PASSWORD_DEFAULT)`, which is **bcrypt** | Company rule is **Argon2id**. Implement `Argon2idPasswordHasher` against the same contract (`PASSWORD_ARGON2ID`, tuned `memory_cost`/`time_cost`, `password_needs_rehash` on login). The `php:8.4` image has Argon2 and libsodium |
| `Contracts\Authenticator` / `InMemoryAuthenticator` | Checks against an in-memory map | Test double only |

**Not in PCF v1.1.0 (gaps):** sessions and cookies, CSRF, authentication middleware, RBAC/authorisation, MFA/TOTP, WebAuthn, encryption-at-rest helpers, signed URLs, rate limiting, input validation/DTO mapping, HMAC webhook verification, audit log. PCF's sample `config/security.php` declares `session_*`/`csrf_enabled` keys, but **no code reads them**. All of these are Sprint 1 to 3 work in PaxofiCloud (§14). They must use **libsodium** (`sodium_crypto_secretbox`, `sodium_crypto_aead_xchacha20poly1305_ietf_*`, `sodium_crypto_generichash`) and `hash_hmac` + `hash_equals`. Never hand-rolled crypto.

## 13. Events, logging, metrics, health, CLI

- **Events:** `InMemoryEventDispatcher::listen(EventClass::class, EventListener)` and `dispatch(Event)` are synchronous and in-process, matching the exact class name. There is no async or outbox. For cross-module side effects after commit, PaxofiCloud uses an **outbox table** written inside the same transaction and relayed to the queue.
- **Logging:** `Contracts\Logger` (`debug/info/warning/error`). `StreamLogger` writes one JSON line per record (`timestamp, level, message, context`). There are no `critical` or `notice` levels and no automatic request ID. Pass `request_id`, `tenant_id` and `actor_id` in the context, and **never log secrets, tokens, card data or full PII**.
- **Metrics:** `Contracts\Metrics::increment/observe` with tags; `StreamMetrics` writes JSON lines. PCF emits `pcf.queue.*` counters.
- **Health:** `HealthRegistry::register(HealthCheck)`, `check(): array<name, HealthResult>`, `status(): Healthy|Degraded|Unhealthy`. A throwing check becomes Unhealthy **with the exception message**. Do not expose `check()` details publicly; the public `/health` returns status only.
- **CLI:** `CommandRegistry::register(Command)` and `CommandRunner::run($name, $args): int`. There is no argument parser or `bin/` entry point; PaxofiCloud ships `bin/paxoficloud` (worker, scheduler, migrate, reconcile).

## 14. Capability matrix: what PaxofiCloud builds

| Capability | PCF v1.1.0 | PaxofiCloud plan (sprint) |
|---|---|---|
| Kernel, DI, providers, config | ✅ | Use |
| HTTP pipeline, routing, controllers | ✅ (minimal) | Add front controller + emitter, JSON request/response helpers, problem-details errors, security-headers/CORS middleware (S0–S1) |
| PDO, transactions | ✅ | Use; add `fetchOne` helper in app repositories |
| **Migrations** | ❌ | `bin/paxoficloud migrate`: forward-only, versioned SQL files, checksum table (S0) |
| Redis cache, idempotency claim | ✅ | Use; add durable MySQL idempotency records for money/provisioning (S3) |
| Reliable queue + worker | ✅ (at-least-once) | Provisioning engine with MySQL job state, max attempts, scheduled retries, DLQ, compensation (S4) |
| Retry/backoff | ✅ | Use; add circuit breaker per provider (S4) |
| Outbound HTTP | ✅ (transport) | `ProviderHttpClient`: https-only, host allowlist, size cap, redaction, request-ID propagation (S3) |
| Password hashing | ⚠️ bcrypt | `Argon2idPasswordHasher` (S1) |
| Sessions/cookies, CSRF | ❌ | Server-side sessions in Redis, `__Host-` cookies, rotation on login/privilege change; double-submit or synchroniser CSRF tokens (S1) |
| AuthN, MFA (TOTP), WebAuthn (staff) | ❌ | Identity module (S1); WebAuthn verification via a vetted library only after security review, since this is crypto parsing |
| RBAC / tenant authorisation | ❌ (app has `AuthoritativeTenantAuthorizer`) | Extend into roles/permissions (S1) |
| Validation / DTOs | ❌ | Small validator in `PaxofiCloud\Application\Validation` (S1) |
| Encryption at rest (provider creds) | ❌ | `SecretBox` service on libsodium with key IDs for rotation (S3) |
| Rate limiting | ❌ | Redis sliding-window middleware (S1) |
| Webhook HMAC + replay window | ❌ | Per-gateway verifier, `hash_equals`, timestamp window, event-ID dedupe table (S3) |
| Audit log (hash-chained) | ❌ (app has `AuditRecorder` port) | Append-only `audit_events` with `prev_hash`/`hash` (S1) |
| Mail / SMS | ❌ (config only) | Notification adapters via transactional provider API (S8) |
| Scheduler (cron) | ❌ | `bin/paxoficloud schedule:run` every minute (S4) |

Rule of thumb: put something in PCF only if it is product-agnostic and Paxofi Pay will also need it (sessions, CSRF, validation, migrations, rate limiting, webhook verification are candidates). It goes through the PCF change process (PR in `paxofi-core-framework`, tests, new minor release). Until then, build it in PaxofiCloud behind an interface so it can move later.

## 15. Framework issues

Defects and hardening items found while writing this reference (configuration-key mismatches, unbounded reliable-queue retries, idempotency semantics, outbound-HTTP hardening, password-hash default) are tracked privately in the `paxofi-core-framework` issue tracker, not in this public repository. Until they are fixed, the PaxofiCloud mitigations in sections 4, 8, 9, 11 and 12 apply.

## 16. Reference wiring for PaxofiCloud

```php
<?php // public/index.php — the only PHP file under the web root
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Paxofi\Core\Bootstrap\ApplicationFactory;
use Paxofi\Core\Configuration\{Bootstrap as ConfigBootstrap, Provider as ConfigProvider, Validator};
use Paxofi\Core\Http\{Kernel, Request, Router};
use Paxofi\Core\Infrastructure\Provider as InfrastructureProvider;

$config = ConfigBootstrap::fromDirectory(dirname(__DIR__), useCache: getenv('APP_ENV') === 'production');
(new Validator())->required($config->environment(), ['APP_KEY', 'DB_DSN', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST']);

$app = (new ApplicationFactory())->create(null, [
    ConfigProvider::fromBootstrap($config),   // first: Infrastructure\Provider reads it
    new InfrastructureProvider(),
    new PaxofiCloud\Infrastructure\Providers\HttpProvider(),     // router, middleware, error handler
    new PaxofiCloud\Infrastructure\Providers\IdentityProvider(),
    // …one provider per module
]);

$c = $app->container();
$kernel = new Kernel($app, $c->get(Router::class), $c->get('http.middleware'), $c->get(PaxofiCloud\Infrastructure\Http\ProblemDetailsHandler::class));

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_') && is_string($v)) {
        $headers[str_replace('_', '-', substr($k, 5))] = $v;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
}

$response = $kernel->handle(new Request(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    $headers,
    (string) file_get_contents('php://input', length: 1_048_576), // 1 MiB body cap
    $_GET,
));

http_response_code($response->status());
foreach ($response->headers() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo $response->body();
```

Layering inside PaxofiCloud (unchanged from ADR-001): `Domain` (pure PHP, no PCF imports) → `Application` (use cases + ports, may use `Paxofi\Core\Contracts\*`) → `Infrastructure` (adapters implementing ports with PCF implementations) → `Http` (controllers and middleware). Only `Infrastructure` and `Http` may reference concrete `Paxofi\Core\*` classes.
