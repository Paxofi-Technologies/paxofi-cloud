<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Registrar\RegistrarUnavailable;

/**
 * The outcome of the most recent registrar availability check (DS-13), so staff
 * can see why names show "Couldn't check" without shell access. Only a fixed
 * outcome code, counts and a time are kept, never the names asked about.
 */
final readonly class RegistrarCheckHealth
{
    public const string KEY = 'pc:regcheck:health';
    public const int TTL_SECONDS = 604800;
    public const array OUTCOMES = ['answered', 'not_connected', ...RegistrarUnavailable::REASONS];

    public function __construct(private RedisClient $redis)
    {
    }

    public function record(string $outcome, int $asked, int $answered, \DateTimeImmutable $at): void
    {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('Unknown registrar check outcome.');
        }
        $this->redis->setEx(self::KEY, self::TTL_SECONDS, json_encode([
            'outcome' => $outcome,
            'asked' => max(0, $asked),
            'answered' => max(0, $answered),
            'at' => $at->format(\DATE_ATOM),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{outcome: string, asked: int, answered: int, at: string}|null
     * @phpstan-impure
     */
    public function latest(): ?array
    {
        $raw = $this->redis->get(self::KEY);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $outcome = $data['outcome'] ?? null;
        $asked = $data['asked'] ?? null;
        $answered = $data['answered'] ?? null;
        $at = $data['at'] ?? null;
        if (!is_string($outcome) || !in_array($outcome, self::OUTCOMES, true) || !is_int($asked) || !is_int($answered) || !is_string($at)) {
            return null;
        }

        return ['outcome' => $outcome, 'asked' => $asked, 'answered' => $answered, 'at' => $at];
    }
}
