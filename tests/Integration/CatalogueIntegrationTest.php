<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Integration;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Bootstrap\AppFactory;
use PaxofiCloud\Http\HttpRuntime;
use PaxofiCloud\Http\RequestFactory;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Migrations\MigrationLoader;
use PaxofiCloud\Infrastructure\Migrations\Migrator;
use PaxofiCloud\Infrastructure\Migrations\MysqlAdvisoryLock;
use PDO;
use PDOException;

/**
 * GET /api/v1/catalogue through the real HTTP stack and MySQL 8.4 (CAT-006,
 * CAT-003, PRC-002, PRC-004; commerce threat model C-07, C-09, C-12).
 */
final class CatalogueIntegrationTest extends IntegrationTestCase
{
    private const string INTERNAL_REFERENCE = 'cx32-internal-ref';
    private const int INTERNAL_COST = 777_777;

    protected function setUp(): void
    {
        parent::setUp();
        self::resetDatabase();
        $app = AppFactory::create(self::basePath());
        $repository = Resolve::get($app->container(), Repository::class);
        (new Migrator(Resolve::get($app->container(), Connection::class), $repository, new MysqlAdvisoryLock($repository)))
            ->migrate((new MigrationLoader())->load(self::basePath() . '/migrations'));
        self::seed(self::pdo());
    }

    public function testThePublicCatalogueListsOnlyPlansOnSale(): void
    {
        $response = $this->get();

        self::assertSame(200, $response->status());
        self::assertSame('public, max-age=300', $response->header('cache-control'));
        $body = $response->body();
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $plans = self::dig($data, 'products', 0, 'plans');
        self::assertCount(1, self::array(self::dig($data, 'products')));
        self::assertSame(['vps-small'], array_column(self::array($plans), 'slug'), 'draft and incompletely priced plans are hidden');
        self::assertSame(['NGN' => ['first_term' => 1_500_000, 'renewal' => 1_500_000], 'USD' => ['first_term' => 1_000, 'renewal' => 1_000]], self::dig($plans, 0, 'cycles', 0, 'prices'));
        self::assertSame(['fsn1', 'hel1'], array_column(self::array(self::dig($plans, 0, 'options', 0, 'choices')), 'code'));

        // C-09: provider mapping and cost never reach a customer.
        foreach ([self::INTERNAL_REFERENCE, 'hetzner', (string) self::INTERNAL_COST, '9999', 'adapter', 'cost', 'margin'] as $internal) {
            self::assertStringNotContainsStringIgnoringCase($internal, $body);
        }
    }

    public function testDatabaseRejectsBadCatalogueData(): void
    {
        $pdo = self::pdo();
        foreach ([
            "INSERT INTO catalogue_prices (id, plan_id, cycle, currency, first_term_minor, renewal_minor, effective_from, created_at) VALUES (REPEAT('x', 32), REPEAT('b', 32), 'monthly', 'NGN', -1, 0, NOW(6), NOW(6))",
            "INSERT INTO catalogue_prices (id, plan_id, cycle, currency, first_term_minor, renewal_minor, effective_from, created_at) VALUES (REPEAT('y', 32), REPEAT('b', 32), 'monthly', 'EUR', 1, 1, NOW(6), NOW(6))",
            "INSERT INTO catalogue_plans (id, product_id, slug, name, state, created_at) VALUES (REPEAT('z', 32), REPEAT('a', 32), 'Bad Slug', 'x', 'draft', NOW(6))",
            "INSERT INTO catalogue_plans (id, product_id, slug, name, state, created_at) VALUES (REPEAT('w', 32), REPEAT('a', 32), 'ok-slug', 'x', 'live', NOW(6))",
            "INSERT INTO catalogue_plan_options (id, plan_id, code, label, kind, min_value, max_value) VALUES (REPEAT('v', 32), REPEAT('b', 32), 'ips', 'IPs', 'integer', NULL, 4)",
        ] as $sql) {
            try {
                $pdo->exec($sql);
                self::fail('Accepted: ' . $sql);
            } catch (PDOException $e) {
                $code = $e->errorInfo[1] ?? null;
                self::assertContains(is_int($code) ? $code : 0, [3819, 1452], $e->getMessage());
            }
        }
    }

    private static function seed(PDO $pdo): void
    {
        $now = "'2026-10-01 00:00:00.000000'";
        $pdo->exec("INSERT INTO catalogue_products (id, slug, kind, name, description, created_at) VALUES (REPEAT('a', 32), 'cloud-vps', 'vps', 'Cloud VPS', 'Virtual servers', $now)");
        foreach ([['b', 'vps-small', 'active'], ['c', 'vps-draft', 'draft'], ['d', 'vps-unpriced', 'active']] as [$id, $slug, $state]) {
            $pdo->exec("INSERT INTO catalogue_plans (id, product_id, slug, name, state, created_at) VALUES (REPEAT('$id', 32), REPEAT('a', 32), '$slug', '$slug', '$state', $now)");
            $pdo->exec("INSERT INTO catalogue_plan_cycles (plan_id, cycle) VALUES (REPEAT('$id', 32), 'monthly')");
        }
        foreach (['b', 'c'] as $id) {
            $pdo->exec("INSERT INTO catalogue_prices VALUES (REPEAT('n$id', 16), REPEAT('$id', 32), 'monthly', 'NGN', 1500000, 1500000, $now, $now)");
            $pdo->exec("INSERT INTO catalogue_prices VALUES (REPEAT('u$id', 16), REPEAT('$id', 32), 'monthly', 'USD', 1000, 1000, $now, $now)");
        }
        // A future price must not show yet (PRC-005); the unpriced plan has NGN only (PRC-002).
        $pdo->exec("INSERT INTO catalogue_prices VALUES (REPEAT('f', 32), REPEAT('b', 32), 'monthly', 'USD', 9999, 9999, '2099-01-01 00:00:00', $now)");
        $pdo->exec("INSERT INTO catalogue_prices VALUES (REPEAT('g', 32), REPEAT('d', 32), 'monthly', 'NGN', 1500000, 1500000, $now, $now)");
        $pdo->exec("INSERT INTO catalogue_plan_options (id, plan_id, code, label, kind, required) VALUES (REPEAT('o', 32), REPEAT('b', 32), 'location', 'Location', 'enum', 1)");
        $pdo->exec("INSERT INTO catalogue_option_choices (option_id, code, label, sort_order) VALUES (REPEAT('o', 32), 'fsn1', 'Falkenstein', 1), (REPEAT('o', 32), 'hel1', 'Helsinki', 2)");
        $pdo->exec("INSERT INTO catalogue_provider_mappings (plan_id, adapter, reference, updated_at) VALUES (REPEAT('b', 32), 'hetzner', '" . self::INTERNAL_REFERENCE . "', $now)");
        $pdo->exec("INSERT INTO catalogue_costs VALUES (REPEAT('k', 32), REPEAT('b', 32), 'monthly', 'NGN', " . self::INTERNAL_COST . ", $now, $now)");
    }

    private static function dig(mixed $node, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }

    /** @return array<array-key, mixed> */
    private static function array(mixed $node): array
    {
        self::assertIsArray($node);

        return $node;
    }

    private function get(): HttpResponse
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        $request = (new RequestFactory())->fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/catalogue', 'REMOTE_ADDR' => '198.18.' . random_int(0, 255) . '.' . random_int(1, 254)], [], $stream);

        return (new HttpRuntime(AppFactory::create(self::basePath())))->handle($request);
    }
}
