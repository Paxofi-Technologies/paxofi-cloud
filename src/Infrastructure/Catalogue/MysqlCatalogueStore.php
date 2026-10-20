<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Catalogue;

use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\CatalogueStore;
use PaxofiCloud\Domain\Catalogue\BillingCycle;
use PaxofiCloud\Domain\Catalogue\CatalogueProduct;
use PaxofiCloud\Domain\Catalogue\DatedPrice;
use PaxofiCloud\Domain\Catalogue\OptionChoice;
use PaxofiCloud\Domain\Catalogue\OptionDefinition;
use PaxofiCloud\Domain\Catalogue\OptionKind;
use PaxofiCloud\Domain\Catalogue\PlanDefinition;
use PaxofiCloud\Domain\Catalogue\PlanState;
use PaxofiCloud\Domain\Catalogue\ProductKind;
use PaxofiCloud\Domain\Money\Money;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * Reads the customer-facing catalogue tables only: never
 * catalogue_provider_mappings or catalogue_costs (commerce threat model CD-5).
 * The whole catalogue is small (tens of plans), so it is loaded in a few
 * queries rather than per plan.
 */
final readonly class MysqlCatalogueStore implements CatalogueStore
{
    public function __construct(private Repository $repository)
    {
    }

    public function products(): array
    {
        $plansByProduct = [];
        foreach ($this->plans(null) as $productId => $plans) {
            $plansByProduct[$productId] = $plans;
        }
        $products = [];
        foreach ($this->repository->fetchAll('SELECT id, slug, kind, name, description FROM catalogue_products ORDER BY sort_order, name') as $row) {
            $id = Row::string($row, 'id');
            $products[] = new CatalogueProduct(
                $id,
                Row::string($row, 'slug'),
                ProductKind::from(Row::string($row, 'kind')),
                Row::string($row, 'name'),
                Row::string($row, 'description'),
                $plansByProduct[$id] ?? [],
            );
        }

        return $products;
    }

    public function plan(string $slug): ?PlanDefinition
    {
        foreach ($this->plans($slug) as $plans) {
            return $plans[0] ?? null;
        }

        return null;
    }

    /** @return array<string, list<PlanDefinition>> plans grouped by product ID */
    private function plans(?string $slug): array
    {
        $rows = $this->repository->fetchAll(
            'SELECT p.id, p.product_id, p.slug, p.name, p.description, p.state, pr.kind
             FROM catalogue_plans p JOIN catalogue_products pr ON pr.id = p.product_id
             WHERE (:any = 1 OR p.slug = :slug)
             ORDER BY p.sort_order, p.name',
            ['any' => $slug === null ? 1 : 0, 'slug' => $slug ?? ''],
        );
        if ($rows === []) {
            return [];
        }
        $cycles = $this->grouped('SELECT plan_id, cycle FROM catalogue_plan_cycles ORDER BY plan_id, cycle', 'plan_id');
        $prices = $this->grouped('SELECT plan_id, cycle, currency, first_term_minor, renewal_minor, effective_from FROM catalogue_prices', 'plan_id');
        $options = $this->grouped('SELECT id, plan_id, code, label, kind, required, min_value, max_value FROM catalogue_plan_options ORDER BY sort_order, code', 'plan_id');
        $choices = $this->grouped('SELECT option_id, code, label FROM catalogue_option_choices ORDER BY sort_order, code', 'option_id');
        $optionPrices = $this->grouped('SELECT option_id, choice_code, cycle, currency, amount_minor, effective_from FROM catalogue_option_prices', 'option_id');

        $plans = [];
        foreach ($rows as $row) {
            $id = Row::string($row, 'id');
            $plans[Row::string($row, 'product_id')][] = new PlanDefinition(
                $id,
                Row::string($row, 'slug'),
                ProductKind::from(Row::string($row, 'kind')),
                Row::string($row, 'name'),
                Row::string($row, 'description'),
                PlanState::from(Row::string($row, 'state')),
                array_map(static fn (array $c): BillingCycle => BillingCycle::from(Row::string($c, 'cycle')), $cycles[$id] ?? []),
                array_map(static fn (array $p): DatedPrice => self::price($p, 'first_term_minor', 'renewal_minor'), $prices[$id] ?? []),
                array_map(fn (array $o): OptionDefinition => $this->option($o, $choices, $optionPrices), $options[$id] ?? []),
            );
        }

        return $plans;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, list<array<string, mixed>>> $choices
     * @param array<string, list<array<string, mixed>>> $optionPrices
     */
    private function option(array $row, array $choices, array $optionPrices): OptionDefinition
    {
        $id = Row::string($row, 'id');
        $pricesByChoice = [];
        foreach ($optionPrices[$id] ?? [] as $price) {
            $pricesByChoice[Row::string($price, 'choice_code')][] = self::price($price, 'amount_minor', 'amount_minor');
        }
        $kind = OptionKind::from(Row::string($row, 'kind'));

        return new OptionDefinition(
            Row::string($row, 'code'),
            Row::string($row, 'label'),
            $kind,
            Row::int($row, 'required') === 1,
            $row['min_value'] === null ? null : Row::int($row, 'min_value'),
            $row['max_value'] === null ? null : Row::int($row, 'max_value'),
            array_map(static fn (array $c): OptionChoice => new OptionChoice(
                Row::string($c, 'code'),
                Row::string($c, 'label'),
                $pricesByChoice[Row::string($c, 'code')] ?? [],
            ), $choices[$id] ?? []),
            $kind === OptionKind::Choice ? [] : ($pricesByChoice[''] ?? []),
        );
    }

    /** @param array<string, mixed> $row */
    private static function price(array $row, string $first, string $renewal): DatedPrice
    {
        $currency = Row::string($row, 'currency');

        return new DatedPrice(
            BillingCycle::from(Row::string($row, 'cycle')),
            Money::of(Row::int($row, $first), $currency),
            Money::of(Row::int($row, $renewal), $currency),
            new \DateTimeImmutable(Row::string($row, 'effective_from'), new \DateTimeZone('UTC')),
        );
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function grouped(string $sql, string $key): array
    {
        $grouped = [];
        foreach ($this->repository->fetchAll($sql) as $row) {
            $grouped[Row::string($row, $key)][] = $row;
        }

        return $grouped;
    }
}
