<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Pricing;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\FxRateStore;
use PaxofiCloud\Application\Pricing\StoredRates;
use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Pricing\DecimalRate;
use PaxofiCloud\Domain\Pricing\ExchangeRates;
use PaxofiCloud\Domain\Pricing\FxRateVerdict;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL rate history (migration 0011; append-only for the runtime user). */
final readonly class MysqlFxRateStore implements FxRateStore
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private Connection $connection,
        private Repository $repository,
        private TransactionManager $transactions,
    ) {
    }

    public function latestAccepted(): ?StoredRates
    {
        return $this->hydrate($this->repository->fetchAll(
            "SELECT id, source, verdict, published_at, fetched_at FROM fx_rate_sets WHERE verdict = 'accepted' ORDER BY fetched_at DESC, id DESC LIMIT 1",
        ))[0] ?? null;
    }

    public function record(ExchangeRates $rates, FxRateVerdict $verdict, \DateTimeImmutable $fetchedAt): string
    {
        $id = bin2hex(random_bytes(16));
        $this->transactions->transaction(function () use ($id, $rates, $verdict, $fetchedAt): void {
            $this->connection->execute(
                'INSERT INTO fx_rate_sets (id, source, verdict, published_at, fetched_at, created_at)
                 VALUES (:id, :source, :verdict, :published_at, :fetched_at, :created_at)',
                [
                    'id' => $id,
                    'source' => $rates->source,
                    'verdict' => $verdict->value,
                    'published_at' => self::utc($rates->publishedAt),
                    'fetched_at' => self::utc($fetchedAt),
                    'created_at' => self::utc($fetchedAt),
                ],
            );
            foreach (CostCurrency::cases() as $currency) {
                $this->connection->execute(
                    'INSERT INTO fx_rate_values (set_id, currency, per_usd) VALUES (:set_id, :currency, :per_usd)',
                    ['set_id' => $id, 'currency' => $currency->value, 'per_usd' => $rates->rate($currency)->value],
                );
            }
        });

        return $id;
    }

    public function recent(int $limit): array
    {
        $sets = $this->repository->fetchAll(
            'SELECT id, source, verdict, published_at, fetched_at FROM fx_rate_sets ORDER BY fetched_at DESC, id DESC LIMIT 50',
        );

        return $this->hydrate(array_slice($sets, 0, max(1, $limit)));
    }

    /**
     * @param list<array<string, mixed>> $sets
     * @return list<StoredRates>
     */
    private function hydrate(array $sets): array
    {
        $result = [];
        foreach ($sets as $set) {
            $id = Row::string($set, 'id');
            $perUsd = [];
            foreach ($this->repository->fetchAll('SELECT currency, per_usd FROM fx_rate_values WHERE set_id = :id', ['id' => $id]) as $value) {
                $perUsd[Row::string($value, 'currency')] = new DecimalRate(Row::string($value, 'per_usd'));
            }
            $result[] = new StoredRates(
                $id,
                new ExchangeRates($perUsd, Row::string($set, 'source'), self::parse(Row::string($set, 'published_at'))),
                FxRateVerdict::from(Row::string($set, 'verdict')),
                self::parse(Row::string($set, 'fetched_at')),
            );
        }

        return $result;
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }

    private static function parse(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
