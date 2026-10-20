<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Catalogue;

use PaxofiCloud\Application\Catalogue\PublicCatalogue;
use PaxofiCloud\Domain\Catalogue\PlanState;
use PaxofiCloud\Tests\Support\CatalogueFixtures as F;
use PaxofiCloud\Tests\Support\FixedClock;
use PaxofiCloud\Tests\Support\InMemoryCatalogueStore;
use PHPUnit\Framework\TestCase;

/** CAT-006, PRC-006; commerce threat model C-07, C-09. */
final class PublicCatalogueTest extends TestCase
{
    public function testOnlyPlansOnSaleAreListedWithBothCurrencies(): void
    {
        $store = new InMemoryCatalogueStore([F::vpsProduct([
            F::vpsPlan(),
            F::vpsPlan(PlanState::Draft, slug: 'vps-draft'),
            F::vpsPlan(PlanState::Deprecated, slug: 'vps-old'),
        ])]);
        $view = (new PublicCatalogue($store, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00 UTC'))))->view();

        self::assertSame(['NGN', 'USD'], $view['currencies']);
        self::assertCount(1, $view['products']);
        $plans = self::dig($view, 'products', 0, 'plans');
        self::assertSame(['vps-small'], array_column(self::list($plans), 'slug'));
        self::assertSame(['cycle' => 'annual', 'months' => 12, 'prices' => [
            'NGN' => ['first_term' => 15_000_000, 'renewal' => 16_500_000],
            'USD' => ['first_term' => 10_000, 'renewal' => 11_000],
        ]], self::dig($plans, 0, 'cycles', 1));
        self::assertSame(['monthly' => ['NGN' => 50_000, 'USD' => 300], 'annual' => ['NGN' => 500_000, 'USD' => 3_000]], self::dig($plans, 0, 'options', 1, 'unit_prices'));
        self::assertSame(['fsn1', 'hel1'], array_column(self::list(self::dig($plans, 0, 'options', 0, 'choices')), 'code'));
    }

    public function testProductsWithNothingOnSaleAreLeftOut(): void
    {
        $store = new InMemoryCatalogueStore([F::vpsProduct([F::vpsPlan(PlanState::Draft)])]);

        self::assertSame([], (new PublicCatalogue($store, new FixedClock(new \DateTimeImmutable('2026-10-20 UTC'))))->view()['products']);
    }

    /** CustomerPayloadHasNoProviderDataTest (C-09): only allow-listed keys at every level. */
    public function testThePayloadUsesOnlyAllowListedKeys(): void
    {
        $view = (new PublicCatalogue(new InMemoryCatalogueStore([F::vpsProduct([F::vpsPlan()])]), new FixedClock(new \DateTimeImmutable('2026-10-20 UTC'))))->view();
        $allowed = ['currencies', 'products', 'slug', 'kind', 'name', 'description', 'plans', 'cycles', 'cycle', 'months', 'prices', 'first_term', 'renewal',
            'NGN', 'USD', 'options', 'code', 'label', 'type', 'required', 'min', 'max', 'choices', 'unit_prices', 'monthly', 'annual'];
        $walk = function (mixed $node) use (&$walk, $allowed): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $child) {
                if (is_string($key)) {
                    self::assertContains($key, $allowed, 'unexpected key in the public catalogue');
                }
                $walk($child);
            }
        };
        $walk($view);
        self::assertStringNotContainsStringIgnoringCase('plan-vps-small', (string) json_encode($view), 'internal IDs stay internal');
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
    private static function list(mixed $node): array
    {
        self::assertIsArray($node);

        return $node;
    }
}
