<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Domains;

use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\DomainSearchSettingsStore;
use PaxofiCloud\Application\Domains\DomainSearchSettings;
use PaxofiCloud\Application\Domains\LookupSettings;
use PaxofiCloud\Infrastructure\Persistence\Row;

/** Domain search and per-extension lookup settings in MySQL (migration 0030). A row that no longer validates is ignored. */
final readonly class MysqlDomainSearchSettingsStore implements DomainSearchSettingsStore
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection, private Repository $repository)
    {
    }

    public function settings(): DomainSearchSettings
    {
        $row = $this->repository->fetchAll('SELECT search_tlds, max_ideas, idea_prefixes, idea_suffixes FROM domain_search_settings WHERE id = 1')[0] ?? null;
        if ($row === null) {
            return DomainSearchSettings::defaults();
        }
        try {
            return new DomainSearchSettings(
                self::strings(Row::string($row, 'search_tlds')),
                Row::int($row, 'max_ideas'),
                self::strings(Row::string($row, 'idea_prefixes')),
                self::strings(Row::string($row, 'idea_suffixes')),
            );
        } catch (\InvalidArgumentException|\JsonException) {
            return DomainSearchSettings::defaults();
        }
    }

    public function saveSettings(DomainSearchSettings $settings, string $staffId, \DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'INSERT INTO domain_search_settings (id, search_tlds, max_ideas, idea_prefixes, idea_suffixes, updated_by, updated_at)
             VALUES (1, :tlds, :ideas, :prefixes, :suffixes, :staff, :at)
             ON DUPLICATE KEY UPDATE search_tlds = VALUES(search_tlds), max_ideas = VALUES(max_ideas), idea_prefixes = VALUES(idea_prefixes),
                 idea_suffixes = VALUES(idea_suffixes), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [
                'tlds' => json_encode($settings->searchTlds ?? [], JSON_THROW_ON_ERROR),
                'ideas' => $settings->maxIdeas,
                'prefixes' => json_encode($settings->prefixes, JSON_THROW_ON_ERROR),
                'suffixes' => json_encode($settings->suffixes, JSON_THROW_ON_ERROR),
                'staff' => $staffId,
                'at' => self::utc($at),
            ],
        );
    }

    public function lookups(): array
    {
        $result = [];
        foreach ($this->repository->fetchAll('SELECT tld, mode, whois_server, whois_phrase FROM domain_lookup_settings') as $row) {
            try {
                $tld = Row::string($row, 'tld');
                $server = $row['whois_server'] ?? null;
                $phrase = $row['whois_phrase'] ?? null;
                $result[$tld] = new LookupSettings($tld, Row::string($row, 'mode'), is_string($server) ? $server : null, is_string($phrase) ? $phrase : null);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $result;
    }

    public function saveLookup(LookupSettings $lookup, string $staffId, \DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'INSERT INTO domain_lookup_settings (tld, mode, whois_server, whois_phrase, updated_by, updated_at)
             VALUES (:tld, :mode, :server, :phrase, :staff, :at)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), whois_server = VALUES(whois_server), whois_phrase = VALUES(whois_phrase),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            ['tld' => $lookup->tld, 'mode' => $lookup->mode, 'server' => $lookup->whoisServer, 'phrase' => $lookup->whoisPhrase, 'staff' => $staffId, 'at' => self::utc($at)],
        );
    }

    /**
     * @return list<string>
     * @throws \JsonException
     */
    private static function strings(string $json): array
    {
        $values = json_decode($json, false, 2, JSON_THROW_ON_ERROR);

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }
}
