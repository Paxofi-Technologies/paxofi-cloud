<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Domains;

use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DomainSearchSettingsStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;
use PaxofiCloud\Application\Staff\StaffAuthorizer;
use PaxofiCloud\Domain\Staff\Permission;

/**
 * The admin Domain search page (THREAT-MODEL-DOMAIN-SEARCH.md DS-21 to
 * DS-23). `catalogue.view` reads; `catalogue.manage` changes the search
 * extensions, idea settings and each extension's lookup mode and phrase.
 * Setting or changing a fixed WHOIS server also needs a fresh authenticator
 * code, at most 5 tries per 15 minutes (the DX-11 rule; Super Admin cannot
 * be granted to a sole founder). Every change is audited with before and
 * after; searched names never appear here.
 */
final readonly class DomainSearchAdmin
{
    private const int STEP_UP_ATTEMPTS = 5;
    private const int STEP_UP_WINDOW = 900;

    /** @param \Closure(string, string): bool $verifyCode staff ID, authenticator code */
    public function __construct(
        private StaffAuthorizer $authorizer,
        private DomainSearchSettingsStore $store,
        private ExtensionQuotes $quotes,
        private RateLimiter $limiter,
        private \Closure $verifyCode,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return array{
     *     can_manage: bool, chosen: bool,
     *     settings: array{search_tlds: list<string>, max_ideas: int, prefixes: list<string>, suffixes: list<string>},
     *     limits: array{search_tlds: int, per_search: int, max_ideas: int, words: int},
     *     modes: list<string>,
     *     extensions: list<array{tld: string, in_search: bool, featured: bool, mode: string, whois_server: ?string, whois_phrase: ?string}>
     * }
     * @throws \PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function overview(string $staffId, ClientContext $client): array
    {
        $principal = $this->authorizer->authorize($staffId, Permission::CatalogueView, $client);
        $settings = $this->store->settings();
        $effective = self::searchSet($settings, $this->quotes);
        $featured = $this->quotes->featured();
        $lookups = $this->store->lookups();
        $extensions = [];
        foreach ($this->quotes->extensions() as $tld) {
            $lookup = $lookups[$tld] ?? new LookupSettings($tld);
            $extensions[] = [
                'tld' => $tld,
                'in_search' => in_array($tld, $effective, true),
                'featured' => in_array($tld, $featured, true),
                'mode' => $lookup->mode,
                'whois_server' => $lookup->whoisServer,
                'whois_phrase' => $lookup->whoisPhrase,
            ];
        }

        return [
            'can_manage' => $principal->can(Permission::CatalogueManage),
            'chosen' => $settings->searchTlds !== null,
            'settings' => ['search_tlds' => $effective, 'max_ideas' => $settings->maxIdeas, 'prefixes' => $settings->prefixes, 'suffixes' => $settings->suffixes],
            'limits' => ['search_tlds' => DomainSearchSettings::MAX_SEARCH_TLDS, 'per_search' => DomainSearch::MAX_EXTENSIONS, 'max_ideas' => DomainSearchSettings::MAX_IDEAS, 'words' => DomainSearchSettings::MAX_WORDS],
            'modes' => LookupSettings::MODES,
            'extensions' => $extensions,
        ];
    }

    /**
     * The extensions checked with every search, in order: the staff choice, or
     * the featured extensions plus ng and com.ng. Only extensions on sale.
     *
     * @return list<string>
     */
    public static function searchSet(DomainSearchSettings $settings, ExtensionQuotes $quotes): array
    {
        $chosen = $settings->searchTlds ?? array_values(array_unique([...$quotes->featured(), ...DomainSearch::DEFAULT_SEARCH_EXTENSIONS]));

        return array_values(array_filter($chosen, static fn (string $tld): bool => $quotes->quote($tld) !== null));
    }

    /**
     * @param array<array-key, mixed> $input search_tlds, max_ideas, prefixes, suffixes
     * @throws DomainSearchChangeRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function saveSettings(string $staffId, ClientContext $client, array $input): void
    {
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $client);
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['max_ideas', 'prefixes', 'search_tlds', 'suffixes']) {
            throw new DomainSearchChangeRefused(DomainSearchChangeRefused::INVALID);
        }
        $tlds = self::stringList($input['search_tlds'], DomainSearchSettings::MAX_SEARCH_TLDS);
        $prefixes = self::stringList($input['prefixes'], DomainSearchSettings::MAX_WORDS);
        $suffixes = self::stringList($input['suffixes'], DomainSearchSettings::MAX_WORDS);
        $ideas = $input['max_ideas'];
        if ($tlds === null || $prefixes === null || $suffixes === null || !is_int($ideas)) {
            throw new DomainSearchChangeRefused(DomainSearchChangeRefused::INVALID);
        }
        foreach ($tlds as $tld) {
            if ($this->quotes->quote($tld) === null) {
                throw new DomainSearchChangeRefused(DomainSearchChangeRefused::NOT_ON_SALE);
            }
        }
        try {
            $next = new DomainSearchSettings($tlds, $ideas, NameIdeas::clean($prefixes), NameIdeas::clean($suffixes));
        } catch (\InvalidArgumentException) {
            throw new DomainSearchChangeRefused(DomainSearchChangeRefused::INVALID);
        }
        $before = $this->store->settings();
        $this->store->saveSettings($next, $staffId, $this->clock->now());
        $this->audit->record(AuditEventType::DOMAIN_SEARCH_SETTINGS_CHANGED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $staffId,
            'search_before' => self::code($before->searchTlds === null ? 'default' : implode('_', $before->searchTlds)),
            'search_after' => self::code(implode('_', $tlds)),
            'search_count' => count($tlds),
            'ideas_before' => $before->maxIdeas,
            'ideas_after' => $next->maxIdeas,
            'words_after' => self::code(implode('_', [...$next->prefixes, ...$next->suffixes])),
        ]);
    }

    /** An audit detail value: `a-z 0-9 _ . -`, at most 64 characters, `none` when empty. */
    private static function code(string $value): string
    {
        $value = substr(preg_replace('/[^a-z0-9_.-]/', '', $value) ?? '', 0, 64);

        return preg_match('/^[a-z0-9]/', $value) === 1 ? $value : 'none';
    }

    /**
     * @param array<array-key, mixed> $input tld, mode, whois_server (string or null), whois_phrase (string or null), code (when the server changes)
     * @throws DomainSearchChangeRefused|\PaxofiCloud\Application\Staff\StaffPermissionDenied
     */
    public function saveLookup(string $staffId, ClientContext $client, array $input): void
    {
        $this->authorizer->authorize($staffId, Permission::CatalogueManage, $client);
        if (array_diff(array_keys($input), ['tld', 'mode', 'whois_server', 'whois_phrase', 'code']) !== []) {
            throw new DomainSearchChangeRefused(DomainSearchChangeRefused::INVALID);
        }
        $tld = $input['tld'] ?? null;
        $mode = $input['mode'] ?? null;
        $server = $input['whois_server'] ?? null;
        $phrase = $input['whois_phrase'] ?? null;
        if (!is_string($tld) || !is_string($mode) || ($server !== null && !is_string($server)) || ($phrase !== null && !is_string($phrase))) {
            throw new DomainSearchChangeRefused(DomainSearchChangeRefused::INVALID);
        }
        $server = $server === null || trim($server) === '' ? null : strtolower(trim($server));
        $phrase = $phrase === null || trim($phrase) === '' ? null : trim($phrase);
        try {
            $next = new LookupSettings(strtolower($tld), $mode, $server, $phrase);
        } catch (\InvalidArgumentException) {
            throw new DomainSearchChangeRefused(DomainSearchChangeRefused::INVALID);
        }
        if ($this->quotes->quote($next->tld) === null) {
            throw new DomainSearchChangeRefused(DomainSearchChangeRefused::NOT_ON_SALE);
        }
        $before = $this->store->lookups()[$next->tld] ?? new LookupSettings($next->tld);
        if ($next->whoisServer !== null && $next->whoisServer !== $before->whoisServer) {
            // DS-21, DS-T13: pointing our WHOIS client at a new host needs a fresh code.
            $retry = $this->limiter->hit('staff:domain_search:' . $staffId, self::STEP_UP_ATTEMPTS, self::STEP_UP_WINDOW);
            if ($retry > 0) {
                throw new DomainSearchChangeRefused(DomainSearchChangeRefused::THROTTLED, $retry);
            }
            $code = $input['code'] ?? null;
            if (!is_string($code) || preg_match('/^\d{6}$/', $code) !== 1 || !($this->verifyCode)($staffId, $code)) {
                $this->audit->record(AuditEventType::DOMAIN_SEARCH_CHANGE_REFUSED, AuditEvent::FAILURE, $client, details: ['actor' => $staffId, 'tld' => $next->tld, 'reason' => DomainSearchChangeRefused::STEP_UP]);

                throw new DomainSearchChangeRefused(DomainSearchChangeRefused::STEP_UP);
            }
        }
        $this->store->saveLookup($next, $staffId, $this->clock->now());
        $this->audit->record(AuditEventType::EXTENSION_LOOKUP_CHANGED, AuditEvent::SUCCESS, $client, details: [
            'actor' => $staffId,
            'tld' => $next->tld,
            'mode_before' => $before->mode,
            'mode_after' => $next->mode,
            'whois_server_before' => self::code($before->whoisServer ?? ''),
            'whois_server_after' => self::code($next->whoisServer ?? ''),
            'whois_phrase_changed' => $before->whoisPhrase !== $next->whoisPhrase,
        ]);
    }

    /** @return list<string>|null */
    private static function stringList(mixed $value, int $max): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            return null;
        }
        foreach ($value as $item) {
            if (!is_string($item) || strlen($item) > 127) {
                return null;
            }
        }

        /** @var list<string> $value */
        return array_map(static fn (string $s): string => strtolower(trim(ltrim(trim($s), '.'))), $value);
    }
}
