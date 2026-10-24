<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\DomainPriceFeed;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldCost;
use PaxofiCloud\Domain\Registrar\TldCostList;
use PaxofiCloud\Domain\Registrar\TldName;

/** Returns or throws scripted results in order; any call beyond the script fails the test. */
final class ScriptedPriceFeed implements DomainPriceFeed
{
    /** @var list<TldCostList|RegistrarUnavailable> */
    private array $script = [];
    public int $calls = 0;

    public function provider(): string
    {
        return 'domainnameapi';
    }

    public function then(TldCostList|RegistrarUnavailable $result): self
    {
        $this->script[] = $result;

        return $this;
    }

    public function fetchAll(): TldCostList
    {
        $this->calls++;
        $next = array_shift($this->script) ?? throw new \LogicException('Unexpected price feed call.');
        if ($next instanceof RegistrarUnavailable) {
            throw $next;
        }

        return $next;
    }

    /** @param array<string, int> $registerMinorByTld renew = register + 100 */
    public static function list(array $registerMinorByTld, string $environment = 'live', CostCurrency $currency = CostCurrency::USD): TldCostList
    {
        $items = [];
        foreach ($registerMinorByTld as $tld => $minor) {
            $items[] = new TldCost(new TldName($tld), CostAmount::ofMinor($currency, $minor), CostAmount::ofMinor($currency, $minor + 100), null, null, 1, 10, 'active');
        }

        return new TldCostList($items, [], $environment);
    }
}
