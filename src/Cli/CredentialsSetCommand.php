<?php

declare(strict_types=1);

namespace PaxofiCloud\Cli;

use Paxofi\Core\Contracts\Command;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Infrastructure\Secrets\InvalidKeyRing;
use PaxofiCloud\Infrastructure\Secrets\StoredCredential;

/**
 * `bin/paxoficloud credentials:set <id> <provider> <environment> <access-level> <review-due-on>`
 *
 * Stores (or replaces) a provider API token, encrypted (SRS SEC-004/SEC-005).
 * The token is read from standard input only; see PROVIDER-CREDENTIALS.md.
 * The review date must fall within the next year, so every token is
 * reviewed and rotated at least yearly.
 */
final readonly class CredentialsSetCommand implements Command
{
    public const string USAGE = 'Usage: credentials:set <id> <provider> <dev|staging|prod> <read-only|read-write> <review-due-on YYYY-MM-DD>  (token on standard input)';

    public function __construct(
        private CredentialStoreAccess $access,
        private Console $console,
        private Clock $clock,
    ) {
    }

    public function name(): string
    {
        return 'credentials:set';
    }

    public function execute(array $arguments): int
    {
        if (count($arguments) !== 5) {
            $this->console->error(self::USAGE);

            return 1;
        }
        [$id, $provider, $environment, $accessLevel, $reviewDue] = $arguments;

        try {
            $credential = new StoredCredential($id, $provider, $environment, $accessLevel);
            $reviewDueOn = $this->reviewDate($reviewDue);
            $store = $this->access->store();
            $secret = $this->console->readSecret();
        } catch (\InvalidArgumentException | \UnexpectedValueException | InvalidKeyRing $error) {
            $this->console->error($error->getMessage());

            return 1;
        }

        try {
            $store->save($credential, $secret, $reviewDueOn);
        } catch (\InvalidArgumentException $error) {
            $this->console->error($error->getMessage());

            return 1;
        } finally {
            sodium_memzero($secret);
        }

        $this->console->line(sprintf('Stored credential "%s" (%s, %s, %s); review due %s.', $id, $provider, $environment, $accessLevel, $reviewDueOn->format('Y-m-d')));

        return 0;
    }

    private function reviewDate(string $value): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('The review date must be a valid YYYY-MM-DD date.');
        }
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone($utc)->format('Y-m-d'), $utc);
        if ($date <= $today || $date > $today->modify('+1 year')) {
            throw new \InvalidArgumentException('The review date must be after today and at most one year ahead.');
        }

        return $date;
    }
}
