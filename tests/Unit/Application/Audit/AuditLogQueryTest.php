<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Audit;

use PaxofiCloud\Application\Audit\AuditLogQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** §6.24: staff filters are checked against fixed lists and patterns before any query. */
final class AuditLogQueryTest extends TestCase
{
    public function testAcceptsEveryFilterAndLowerCasesTheSubject(): void
    {
        $query = AuditLogQuery::from(['before' => '120', 'outcome' => 'failure', 'group' => 'staff', 'subject' => 'ABCDEF0123456789ABCDEF0123456789']);

        self::assertSame([120, 'failure', 'staff', 'abcdef0123456789abcdef0123456789'], [$query->beforeSeq, $query->outcome, $query->group, $query->subject]);
        self::assertSame(['outcome' => 'failure', 'group' => 'staff', 'subject' => 'abcdef0123456789abcdef0123456789'], $query->filters(), 'the page position is not a filter');
    }

    public function testEmptyValuesMeanNoFilter(): void
    {
        $query = AuditLogQuery::from(['before' => '', 'outcome' => '', 'unknown' => 'x']);

        self::assertSame([null, null, null, null], [$query->beforeSeq, $query->outcome, $query->group, $query->subject]);
        self::assertSame([], $query->filters());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'zero cursor' => [['before' => '0'], 'before'];
        yield 'negative cursor' => [['before' => '-5'], 'before'];
        yield 'cursor with SQL' => [['before' => '5 OR 1=1'], 'before'];
        yield 'huge cursor' => [['before' => str_repeat('9', 19)], 'before'];
        yield 'array cursor' => [['before' => ['1']], 'before'];
        yield 'unknown outcome' => [['outcome' => 'maybe'], 'outcome'];
        yield 'unknown group' => [['group' => 'payroll'], 'group'];
        yield 'wildcard group' => [['group' => '%'], 'group'];
        yield 'short subject' => [['subject' => 'abc'], 'subject'];
        yield 'subject with quote' => [['subject' => str_repeat('a', 31) . '"'], 'subject'];
        yield 'subject with LIKE wildcard' => [['subject' => str_repeat('a', 31) . '%'], 'subject'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('refused')]
    public function testRefusesAnythingOutsideTheFixedListsAndPatterns(array $input, string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($field);
        AuditLogQuery::from($input);
    }
}
