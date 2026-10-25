<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Billing;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Billing\BillingAccount;
use PaxofiCloud\Application\Billing\BillingDetails;
use PaxofiCloud\Application\Contracts\BillingStore;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** MySQL billing details (migration 0023, commerce threat model CD-21). */
final readonly class MysqlBillingStore implements BillingStore
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function find(string $accountId): ?BillingAccount
    {
        $row = $this->repository->fetchAll(
            'SELECT full_name, company, address_line1, address_line2, city, region, postcode, country, phone_e164, tin, billing_currency, currency_locked_at
             FROM account_billing_details WHERE account_id = :account',
            ['account' => $accountId],
        )[0] ?? null;
        if ($row === null) {
            return null;
        }
        $locked = self::nullable($row, 'currency_locked_at');

        return new BillingAccount(
            BillingDetails::stored(
                Row::string($row, 'full_name'),
                self::nullable($row, 'company'),
                Row::string($row, 'address_line1'),
                self::nullable($row, 'address_line2'),
                Row::string($row, 'city'),
                Row::string($row, 'region'),
                self::nullable($row, 'postcode'),
                Row::string($row, 'country'),
                Row::string($row, 'phone_e164'),
                self::nullable($row, 'tin'),
            ),
            Currency::fromCode(Row::string($row, 'billing_currency')),
            $locked === null ? null : new \DateTimeImmutable($locked, new \DateTimeZone('UTC')),
        );
    }

    public function save(string $accountId, BillingDetails $details, Currency $currency, \DateTimeImmutable $at): void
    {
        // The currency only moves while unlocked; the SQL enforces it too, so a race with the first invoice cannot unlock it.
        $this->connection->execute(
            'INSERT INTO account_billing_details
                (account_id, full_name, company, address_line1, address_line2, city, region, postcode, country, phone_e164, tin, billing_currency, created_at, updated_at)
             VALUES (:account, :name, :company, :line1, :line2, :city, :region, :postcode, :country, :phone, :tin, :currency, :created, :updated)
             ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), company = VALUES(company), address_line1 = VALUES(address_line1),
                 address_line2 = VALUES(address_line2), city = VALUES(city), region = VALUES(region), postcode = VALUES(postcode),
                 country = VALUES(country), phone_e164 = VALUES(phone_e164), tin = VALUES(tin),
                 billing_currency = IF(currency_locked_at IS NULL, VALUES(billing_currency), billing_currency),
                 updated_at = VALUES(updated_at)',
            [
                'account' => $accountId,
                'name' => $details->fullName,
                'company' => $details->company,
                'line1' => $details->addressLine1,
                'line2' => $details->addressLine2,
                'city' => $details->city,
                'region' => $details->region,
                'postcode' => $details->postcode,
                'country' => $details->country,
                'phone' => $details->phone,
                'tin' => $details->tin,
                'currency' => $currency->value,
                'created' => $at->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT),
                'updated' => $at->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT),
            ],
        );
    }

    public function isOwner(string $identityId, string $accountId): bool
    {
        return $this->repository->fetchAll(
            'SELECT 1 AS ok FROM account_members WHERE account_id = :account AND identity_id = :identity AND role = \'owner\'',
            ['account' => $accountId, 'identity' => $identityId],
        ) !== [];
    }

    /** @param array<string, mixed> $row */
    private static function nullable(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : Row::string($row, $column);
    }
}
