<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Security;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Contracts\BreachedPasswordCheck;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * Pwned Passwords k-anonymity range lookup (threat model T-06): only the first
 * five hex characters of the password's SHA-1 leave our network; the rest of
 * the hash is compared locally. Responses are padded so their size does not
 * reveal the bucket. Any failure returns null and the caller fails open.
 */
final readonly class PwnedPasswordsRangeCheck implements BreachedPasswordCheck
{
    public const string BASE_URL = 'https://api.pwnedpasswords.com';

    public function __construct(private ProviderTransport $transport, private Logger $logger)
    {
    }

    /**
     * The API needs no key; the transport's credential header is used to send
     * `Add-Padding: true` instead.
     */
    public static function endpoint(): ProviderEndpoint
    {
        return new ProviderEndpoint(self::BASE_URL, 'Add-Padding', '');
    }

    public function isBreached(#[\SensitiveParameter] string $password): ?bool
    {
        // SHA-1 is what the range protocol is keyed on; it protects nothing here.
        $hash = strtoupper(sha1($password)); // nosemgrep -- the range protocol requires SHA-1 (T-06)
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = $this->transport->send(
                self::BASE_URL,
                ProviderRequest::get('/range/' . strtolower($prefix)),
                new ProviderCredential('pwnedpasswords-padding', 'true'),
            );
        } catch (TransportFailure) {
            $this->logger->warning('password_breach_check.unavailable', ['reason' => 'transport']);

            return null;
        }
        if ($response->status !== 200) {
            $this->logger->warning('password_breach_check.unavailable', ['reason' => 'http_' . $response->status]);

            return null;
        }

        foreach (preg_split('/\R/', $response->body) ?: [] as $line) {
            $parts = explode(':', trim($line), 2);
            if (count($parts) === 2 && strtoupper($parts[0]) === $suffix) {
                // Padding entries carry a count of 0 and are not real hits.
                return (int) $parts[1] > 0;
            }
        }

        return false;
    }
}
