<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Infrastructure\Audit;

use PaxofiCloud\Infrastructure\Audit\AuditChain;
use PHPUnit\Framework\TestCase;

/** Threat model D-14, T-39, T-51: the hashing rules shared by writer and verifier. */
final class AuditChainTest extends TestCase
{
    public function testCanonicalJsonSortsKeysAndKeepsUnicode(): void
    {
        self::assertSame('{"a":1,"b":"Ìkejà/1"}', AuditChain::canonical(['b' => 'Ìkejà/1', 'a' => 1]));
        self::assertSame('{}', AuditChain::canonical([]));
    }

    public function testTheRowHashCoversEveryStoredColumnAndThePreviousHash(): void
    {
        $row = self::row();
        $hash = AuditChain::eventHash($row);
        self::assertSame($hash, AuditChain::eventHash(['seq' => '1'] + $row), 'a driver returning BIGINT as a string changes nothing');
        self::assertSame($hash, AuditChain::eventHash($row + ['hash' => 'ignored', 'salt' => 'ignored']));

        // Listed here, not read from the constant, so dropping a column from the hash fails this test.
        foreach (array_keys(self::row()) as $field) {
            $changed = $row;
            $changed[$field] = $field === 'seq' ? 2 : 'x' . (is_string($row[$field]) ? $row[$field] : '');
            self::assertNotSame($hash, AuditChain::eventHash($changed), $field . ' must be covered by the hash');
        }
    }

    public function testTheContextCommitmentDependsOnTheSaltAndEveryContextField(): void
    {
        $context = ['salt' => str_repeat('ab', 16), 'ip' => '198.51.100.7', 'ip_network' => '198.51.100.0/24', 'country' => 'NG', 'region' => 'Lagos', 'city' => 'Ikeja', 'user_agent' => 'UA', 'browser' => 'Chrome', 'browser_version' => '129', 'os' => 'Windows', 'os_version' => '', 'device_type' => 'desktop'];
        $commitment = AuditChain::contextCommitment($context);

        self::assertNotSame($commitment, AuditChain::contextCommitment(['salt' => str_repeat('cd', 16)] + $context), 'without the salt the commitment reveals nothing (T-51)');
        foreach (array_keys($context) as $field) {
            if ($field === 'salt') {
                continue;
            }
            self::assertNotSame($commitment, AuditChain::contextCommitment([$field => 'changed'] + $context), $field);
        }
    }

    /** @return array<string, mixed> */
    private static function row(): array
    {
        return [
            'seq' => 1,
            'id' => str_repeat('a', 32),
            'occurred_at' => '2026-10-26 09:00:00.000000',
            'event_type' => 'auth.sign_in.succeeded',
            'outcome' => 'success',
            'identity_id' => str_repeat('b', 32),
            'account_id' => str_repeat('c', 32),
            'request_id' => 'req-12345678',
            'details' => '{"method":"password"}',
            'context_commitment' => str_repeat('d', 64),
            'prev_hash' => AuditChain::GENESIS,
        ];
    }
}
