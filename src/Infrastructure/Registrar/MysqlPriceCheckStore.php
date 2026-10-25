<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Registrar;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\PriceCheckStore;
use PaxofiCloud\Application\Registrar\PriceCheckItem;
use PaxofiCloud\Application\Registrar\PriceCheckRun;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Domain\Registrar\TldName;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * MySQL Spaceship price checks (migration 0019, SP-7). A check and its items
 * are written in one transaction and never edited. Prices are USD cents.
 */
final readonly class MysqlPriceCheckStore implements PriceCheckStore
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private Connection $connection,
        private Repository $repository,
        private TransactionManager $transactions,
    ) {
    }

    public function save(PriceCheckRun $run): void
    {
        $this->transactions->transaction(function () use ($run): void {
            $this->connection->execute(
                'INSERT INTO spaceship_price_checks (id, trigger_kind, checked_at, outcome, reason, names_asked, names_answered, names_priced)
                 VALUES (:id, :trigger, :at, :outcome, :reason, :asked, :answered, :priced)',
                [
                    'id' => $run->id,
                    'trigger' => $run->trigger,
                    'at' => $run->checkedAt->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT),
                    'outcome' => $run->failure === null ? 'done' : 'failed',
                    'reason' => $run->failure,
                    'asked' => $run->asked,
                    'answered' => $run->answered(),
                    'priced' => $run->priced(),
                ],
            );
            foreach ($run->items as $item) {
                if ($item->register !== null && $item->register->currency !== CostCurrency::USD) {
                    throw new \LogicException('Price check prices are kept in USD only.');
                }
                $this->connection->execute(
                    'INSERT INTO spaceship_price_check_items (check_id, tld, result, register_minor, other_priced) VALUES (:id, :tld, :result, :register, :other)',
                    [
                        'id' => $run->id,
                        'tld' => $item->tld->value,
                        'result' => $item->result,
                        'register' => $item->register?->minor,
                        'other' => $item->otherPriced === [] ? null : implode(',', $item->otherPriced),
                    ],
                );
            }
        });
    }

    public function latest(): ?PriceCheckRun
    {
        $row = $this->repository->fetchAll(
            'SELECT id, trigger_kind, checked_at, reason, names_asked FROM spaceship_price_checks ORDER BY checked_at DESC, id DESC LIMIT 1',
        )[0] ?? null;
        if ($row === null) {
            return null;
        }
        $id = Row::string($row, 'id');
        $items = [];
        foreach ($this->repository->fetchAll(
            'SELECT tld, result, register_minor, other_priced FROM spaceship_price_check_items WHERE check_id = :id ORDER BY tld',
            ['id' => $id],
        ) as $item) {
            $other = $item['other_priced'] ?? null;
            $items[] = new PriceCheckItem(
                new TldName(Row::string($item, 'tld')),
                Row::string($item, 'result'),
                ($item['register_minor'] ?? null) === null ? null : CostAmount::ofMinor(CostCurrency::USD, Row::int($item, 'register_minor')),
                is_string($other) && $other !== '' ? explode(',', $other) : [],
            );
        }
        $reason = $row['reason'] ?? null;

        return new PriceCheckRun(
            $id,
            Row::string($row, 'trigger_kind'),
            new \DateTimeImmutable(Row::string($row, 'checked_at'), new \DateTimeZone('UTC')),
            is_string($reason) ? $reason : null,
            Row::int($row, 'names_asked'),
            $items,
        );
    }
}
