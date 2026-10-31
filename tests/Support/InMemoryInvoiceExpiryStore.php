<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\InvoiceExpiryStore;

/** Invoice expiry in memory, with the same conditional update as MySQL. */
final class InMemoryInvoiceExpiryStore implements InvoiceExpiryStore
{
    /** @var array<string, array{order: string, account: string, status: string, expires: \DateTimeImmutable, open: bool}> by invoice ID */
    public array $invoices = [];
    /** @var list<string> invoice IDs whose issue entry was reversed */
    public array $reversed = [];
    /** Makes the next expire() throw, as a failed transaction would. */
    public bool $failNext = false;
    /** Simulates a payment that settles between the listing and the update. */
    public ?string $paidConcurrently = null;

    public function add(string $invoiceId, string $orderId, string $accountId, \DateTimeImmutable $expires, bool $openAttempt = false): void
    {
        $this->invoices[$invoiceId] = ['order' => $orderId, 'account' => $accountId, 'status' => 'unpaid', 'expires' => $expires, 'open' => $openAttempt];
    }

    public function dueForExpiry(\DateTimeImmutable $now, int $limit): array
    {
        $due = [];
        foreach ($this->invoices as $id => $i) {
            if ($this->expirable($i, $now)) {
                $due[] = ['invoice_id' => $id, 'order_id' => $i['order'], 'account_id' => $i['account']];
            }
        }

        return array_slice($due, 0, $limit);
    }

    public function expire(string $invoiceId, \DateTimeImmutable $now): bool
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new \RuntimeException('Simulated transaction failure.');
        }
        if ($this->paidConcurrently === $invoiceId) {
            $this->invoices[$invoiceId]['status'] = 'paid';
        }
        $i = $this->invoices[$invoiceId] ?? null;
        if ($i === null || !$this->expirable($i, $now)) {
            return false;
        }
        $this->invoices[$invoiceId]['status'] = 'cancelled';
        $this->reversed[] = $invoiceId;

        return true;
    }

    /** @param array{order: string, account: string, status: string, expires: \DateTimeImmutable, open: bool} $i */
    private function expirable(array $i, \DateTimeImmutable $now): bool
    {
        return $i['status'] === 'unpaid' && $i['expires'] <= $now && !$i['open'];
    }
}
