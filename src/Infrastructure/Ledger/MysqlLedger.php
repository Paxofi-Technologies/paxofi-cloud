<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Ledger;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\Ledger;
use PaxofiCloud\Domain\Ledger\JournalEntry;
use PaxofiCloud\Domain\Ledger\EntryKind;
use PaxofiCloud\Domain\Ledger\JournalLine;
use PaxofiCloud\Domain\Ledger\LedgerAccount;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Infrastructure\Persistence\Row;

/**
 * MySQL journal (migration 0025; PY-13). The runtime user can only SELECT and
 * INSERT here, so posted entries cannot be edited or removed by the
 * application. The unique source key makes posting idempotent.
 */
final readonly class MysqlLedger implements Ledger
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';
    /** Problems listed by verify before it stops counting; the count stays exact. */
    private const int PROBLEM_CAP = 50;

    public function __construct(private Connection $connection, private Repository $repository, private TransactionManager $transactions)
    {
    }

    public function post(JournalEntry $entry): bool
    {
        try {
            return (bool) $this->transactions->transaction(fn (): bool => $this->insert($entry));
        } catch (\RuntimeException $e) {
            if (self::duplicateSource($e)) {
                return false;
            }
            throw $e;
        }
    }

    public function postInCurrentTransaction(JournalEntry $entry): bool
    {
        try {
            return $this->insert($entry);
        } catch (\RuntimeException $e) {
            // The failed INSERT is rolled back on its own; the caller's transaction stays usable.
            if (self::duplicateSource($e)) {
                return false;
            }
            throw $e;
        }
    }

    public function entryForSource(string $sourceKey): ?JournalEntry
    {
        $row = $this->repository->fetchAll(
            'SELECT id, source_key, kind, currency, posted_at, reverses_entry_id FROM journal_entries WHERE source_key = :source',
            ['source' => $sourceKey],
        )[0] ?? null;
        if ($row === null) {
            return null;
        }
        $lines = [];
        foreach ($this->repository->fetchAll(
            'SELECT account_code, customer_account_id, debit_minor, credit_minor FROM journal_lines WHERE entry_id = :id ORDER BY line_no',
            ['id' => Row::string($row, 'id')],
        ) as $line) {
            $account = LedgerAccount::from(Row::string($line, 'account_code'));
            $customer = is_string($line['customer_account_id'] ?? null) ? $line['customer_account_id'] : null;
            $debit = Row::int($line, 'debit_minor');
            $lines[] = $debit > 0
                ? JournalLine::debit($account, $debit, $customer)
                : JournalLine::credit($account, Row::int($line, 'credit_minor'), $customer);
        }
        $reverses = $row['reverses_entry_id'] ?? null;

        return new JournalEntry(
            Row::string($row, 'id'),
            Row::string($row, 'source_key'),
            EntryKind::from(Row::string($row, 'kind')),
            Currency::from(Row::string($row, 'currency')),
            new \DateTimeImmutable(Row::string($row, 'posted_at'), new \DateTimeZone('UTC')),
            $lines,
            is_string($reverses) ? $reverses : null,
        );
    }

    public function balance(LedgerAccount $account, Currency $currency, ?string $customerAccountId = null): int
    {
        if ($account->perCustomer() !== ($customerAccountId !== null)) {
            throw new \InvalidArgumentException('Per-customer accounts are read per customer; others are not.');
        }
        $sql = 'SELECT COALESCE(SUM(l.debit_minor), 0) - COALESCE(SUM(l.credit_minor), 0) AS net
            FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
            WHERE l.account_code = :code AND e.currency = :currency';
        $params = ['code' => $account->value, 'currency' => $currency->value];
        if ($customerAccountId !== null) {
            $sql .= ' AND l.customer_account_id = :customer';
            $params['customer'] = $customerAccountId;
        }

        return Row::int($this->repository->fetchAll($sql, $params)[0] ?? [], 'net');
    }

    /** Checks every rule the database cannot (`ledger:verify`, PY-13, PT-12, PT-22). */
    public function verify(): LedgerVerification
    {
        $problems = [];
        $add = static function (string $problem) use (&$problems): void {
            $problems[] = $problem;
        };
        $count = fn (string $sql): int => Row::int($this->repository->fetchAll($sql)[0] ?? [], 'n');

        foreach ($this->repository->fetchAll(
            'SELECT e.id, COUNT(l.line_no) AS line_count, COALESCE(SUM(l.debit_minor), 0) AS debits, COALESCE(SUM(l.credit_minor), 0) AS credits
             FROM journal_entries e LEFT JOIN journal_lines l ON l.entry_id = e.id
             GROUP BY e.id
             HAVING line_count < 2 OR debits <> credits
             ORDER BY e.id LIMIT ' . self::PROBLEM_CAP,
        ) as $row) {
            $add(sprintf('entry %s: %s', Row::string($row, 'id'), Row::int($row, 'line_count') < 2 ? 'fewer than two lines' : 'debits do not equal credits'));
        }
        foreach ($this->repository->fetchAll(
            "SELECT r.id FROM journal_entries r JOIN journal_entries o ON o.id = r.reverses_entry_id
             WHERE r.kind = 'reversal' AND (o.kind = 'reversal' OR o.currency <> r.currency OR r.source_key <> CONCAT('reversal:', o.id))
             ORDER BY r.id LIMIT " . self::PROBLEM_CAP,
        ) as $row) {
            $add(sprintf('entry %s: reversal does not match the entry it reverses', Row::string($row, 'id')));
        }
        foreach ($this->repository->fetchAll(
            "SELECT i.id FROM invoices i LEFT JOIN journal_entries e ON e.source_key = CONCAT('invoice_issued:', i.id)
             WHERE e.id IS NULL OR e.currency <> i.currency
             ORDER BY i.id LIMIT " . self::PROBLEM_CAP,
        ) as $row) {
            $add(sprintf('invoice %s: no matching issue entry', Row::string($row, 'id')));
        }
        // An issue entry must carry the invoice total on the customer's receivable.
        foreach ($this->repository->fetchAll(
            "SELECT i.id FROM invoices i
             JOIN journal_entries e ON e.source_key = CONCAT('invoice_issued:', i.id)
             LEFT JOIN journal_lines l ON l.entry_id = e.id AND l.account_code = 'receivable'
             GROUP BY i.id, i.total_minor, i.account_id
             HAVING COALESCE(SUM(CASE WHEN l.customer_account_id = i.account_id THEN l.debit_minor ELSE 0 END), 0) <> i.total_minor
             ORDER BY i.id LIMIT " . self::PROBLEM_CAP,
        ) as $row) {
            $add(sprintf('invoice %s: issue entry does not carry the invoice total', Row::string($row, 'id')));
        }

        return new LedgerVerification(
            $count('SELECT COUNT(*) AS n FROM journal_entries'),
            $count('SELECT COUNT(*) AS n FROM journal_lines'),
            $count('SELECT COUNT(*) AS n FROM invoices'),
            $problems,
        );
    }

    private function insert(JournalEntry $entry): bool
    {
        $this->connection->execute(
            'INSERT INTO journal_entries (id, source_key, kind, currency, posted_at, reverses_entry_id) VALUES (:id, :source, :kind, :currency, :posted, :reverses)',
            [
                'id' => $entry->id,
                'source' => $entry->sourceKey,
                'kind' => $entry->kind->value,
                'currency' => $entry->currency->value,
                'posted' => $entry->postedAt->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT),
                'reverses' => $entry->reversesEntryId,
            ],
        );
        foreach ($entry->lines as $i => $line) {
            $this->connection->execute(
                'INSERT INTO journal_lines (entry_id, line_no, account_code, customer_account_id, debit_minor, credit_minor) VALUES (:entry, :no, :code, :customer, :debit, :credit)',
                [
                    'entry' => $entry->id,
                    'no' => $i + 1,
                    'code' => $line->account->value,
                    'customer' => $line->customerAccountId,
                    'debit' => $line->debitMinor,
                    'credit' => $line->creditMinor,
                ],
            );
        }

        return true;
    }

    private static function duplicateSource(\Throwable $e): bool
    {
        // PCF wraps the driver error; look for the unique-key violation on the source key.
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException && ($cause->errorInfo[1] ?? null) === 1062 && str_contains($cause->getMessage(), 'uq_journal_entries_source')) {
                return true;
            }
        }

        return false;
    }
}
