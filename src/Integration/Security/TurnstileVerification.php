<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Security;

use Paxofi\Core\Contracts\Logger;
use PaxofiCloud\Application\Contracts\HumanCheck;
use PaxofiCloud\Application\Contracts\HumanVerification;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * Cloudflare Turnstile `siteverify` (threat model T-02, §6.12). A token is
 * accepted only if Cloudflare says so AND it was issued for our hostname and
 * for this form. The secret travels in the JSON body; neither it nor the token
 * is ever logged.
 */
final readonly class TurnstileVerification implements HumanVerification
{
    public const string BASE_URL = 'https://challenges.cloudflare.com';

    private ProviderCredential $secret;

    public function __construct(
        private ProviderTransport $transport,
        #[\SensitiveParameter] string $secretKey,
        private string $expectedHostname,
        private Logger $logger,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{8,128}$/', $secretKey) !== 1) {
            throw new \InvalidArgumentException('TURNSTILE_SECRET_KEY has an unexpected format.');
        }
        if (preg_match('/^([a-z0-9-]{1,63}\.)*[a-z0-9-]{1,63}$/', $expectedHostname) !== 1) {
            throw new \InvalidArgumentException('The expected Turnstile hostname must be a plain host name.');
        }
        $this->secret = new ProviderCredential('turnstile-secret', $secretKey);
    }

    /**
     * Siteverify needs no auth header; the transport's credential header slot
     * sends a harmless `Cache-Control: no-store` instead.
     */
    public static function endpoint(): ProviderEndpoint
    {
        return new ProviderEndpoint(self::BASE_URL, 'Cache-Control', '');
    }

    public function enabled(): bool
    {
        return true;
    }

    public function verify(#[\SensitiveParameter] ?string $token, string $action, string $clientIp): HumanCheck
    {
        if ($token === null || $token === '') {
            $this->logger->info('turnstile.failed', ['reason' => 'missing', 'action' => $action]);

            return HumanCheck::Failed;
        }

        $body = ['secret' => $this->secret->reveal(), 'response' => $token, 'idempotency_key' => self::uuid()];
        if (filter_var($clientIp, FILTER_VALIDATE_IP) !== false) {
            $body['remoteip'] = $clientIp;
        }
        try {
            $response = $this->transport->send(
                self::BASE_URL,
                ProviderRequest::post('/turnstile/v0/siteverify', $body),
                new ProviderCredential('turnstile-header', 'no-store'),
            );
        } catch (TransportFailure) {
            $this->logger->warning('turnstile.unavailable', ['reason' => 'transport']);

            return HumanCheck::Unavailable;
        }
        if ($response->status !== 200) {
            $this->logger->warning('turnstile.unavailable', ['reason' => 'http_' . $response->status]);

            return HumanCheck::Unavailable;
        }

        try {
            $result = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('turnstile.unavailable', ['reason' => 'malformed']);

            return HumanCheck::Unavailable;
        }
        if (!is_array($result) || !is_bool($result['success'] ?? null)) {
            $this->logger->warning('turnstile.unavailable', ['reason' => 'malformed']);

            return HumanCheck::Unavailable;
        }

        $passed = $result['success'] === true
            && ($result['hostname'] ?? null) === $this->expectedHostname
            && ($result['action'] ?? null) === $action;
        if (!$passed) {
            $this->logger->info('turnstile.failed', ['reason' => self::reason($result, $action, $this->expectedHostname), 'action' => $action]);

            return HumanCheck::Failed;
        }

        return HumanCheck::Passed;
    }

    /** @param array<array-key, mixed> $result */
    private static function reason(array $result, string $action, string $hostname): string
    {
        if ($result['success'] !== true) {
            $codes = $result['error-codes'] ?? [];
            $first = is_array($codes) ? ($codes[0] ?? null) : null;

            // Cloudflare's error codes are a fixed vocabulary; anything else is not logged.
            return is_string($first) && preg_match('/^[a-z-]{1,40}$/', $first) === 1 ? 'rejected:' . $first : 'rejected';
        }

        return ($result['hostname'] ?? null) !== $hostname ? 'wrong_hostname' : 'wrong_action';
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
