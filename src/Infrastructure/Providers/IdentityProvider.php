<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Contracts\AuditLog;
use PaxofiCloud\Application\Contracts\BreachedPasswordCheck;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Contracts\HumanVerification;
use PaxofiCloud\Application\Contracts\MfaStore;
use PaxofiCloud\Application\Contracts\SignInStore;
use PaxofiCloud\Application\Identity\Challenge\ChallengeGate;
use PaxofiCloud\Application\Identity\Mfa\CompleteMfaSignIn;
use PaxofiCloud\Application\Identity\Mfa\MfaCrypto;
use PaxofiCloud\Application\Identity\Mfa\MfaSettings;
use PaxofiCloud\Application\Identity\Mfa\RecoveryCodes;
use PaxofiCloud\Application\Identity\Registration\RegisterCustomer;
use PaxofiCloud\Application\Identity\Registration\ResendVerification;
use PaxofiCloud\Application\Identity\Registration\VerifyEmail;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Application\Identity\SignIn\SignIn;
use PaxofiCloud\Application\Identity\SignIn\SignInAlerts;
use PaxofiCloud\Http\Controllers\ChallengeController;
use PaxofiCloud\Http\Controllers\MfaController;
use PaxofiCloud\Infrastructure\Audit\MysqlAuditLog;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\MysqlEmailOutbox;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Identity\Argon2idPasswordHasher;
use PaxofiCloud\Infrastructure\Identity\MysqlIdentityStore;
use PaxofiCloud\Infrastructure\Identity\MysqlKnownDevices;
use PaxofiCloud\Infrastructure\Identity\MysqlMfaStore;
use PaxofiCloud\Infrastructure\RateLimit\RedisRateLimiter;
use PaxofiCloud\Infrastructure\Secrets\KeyRing;
use PaxofiCloud\Infrastructure\Secrets\SecretCipher;
use PaxofiCloud\Infrastructure\Session\RedisMfaChallengeStore;
use PaxofiCloud\Infrastructure\Session\RedisSessionStore;
use PaxofiCloud\Infrastructure\Session\RedisSignInThrottle;
use PaxofiCloud\Infrastructure\Time\SystemClock;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Security\DisabledBreachedPasswordCheck;
use PaxofiCloud\Integration\Security\DisabledHumanVerification;
use PaxofiCloud\Integration\Security\PwnedPasswordsRangeCheck;
use PaxofiCloud\Integration\Security\TurnstileVerification;
use Paxofi\Core\Application;
use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Container;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;

/**
 * Registration and email verification for the web tier. The outbox here can
 * only seal (EMAIL_OUTBOX_PUBLIC_KEY); it can never open queued mail (E-3).
 */
final class IdentityProvider implements Provider
{
    public function register(Application $application): void
    {
        $container = $application->container();

        $container->singleton(EmailOutbox::class, static function (Container $c): EmailOutbox {
            $publicKey = getenv('EMAIL_OUTBOX_PUBLIC_KEY');
            if (!is_string($publicKey) || $publicKey === '') {
                throw new \InvalidArgumentException('EMAIL_OUTBOX_PUBLIC_KEY must be set to queue email.');
            }

            return new MysqlEmailOutbox(
                Resolve::get($c, Connection::class),
                Resolve::get($c, Repository::class),
                OutboxSealer::forSealing($publicKey),
                new SystemClock(),
            );
        });

        $container->singleton(RegisterCustomer::class, static function (Container $c): RegisterCustomer {
            $terms = Resolve::get($c, Configuration::class)->get('identity.terms_version');

            return new RegisterCustomer(
                new MysqlIdentityStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
                Resolve::get($c, EmailOutbox::class),
                new Argon2idPasswordHasher(),
                Resolve::get($c, TransactionManager::class),
                new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
                new SystemClock(),
                is_string($terms) ? $terms : '',
                self::breachedPasswordCheck($c),
                Resolve::get($c, ChallengeGate::class),
                Resolve::get($c, AuditTrail::class),
            );
        });

        $container->singleton(ResendVerification::class, static fn (Container $c): ResendVerification => new ResendVerification(
            new MysqlIdentityStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
            Resolve::get($c, EmailOutbox::class),
            Resolve::get($c, TransactionManager::class),
            new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
            new SystemClock(),
            Resolve::get($c, ChallengeGate::class),
            Resolve::get($c, AuditTrail::class),
        ));

        $container->singleton(VerifyEmail::class, static fn (Container $c): VerifyEmail => new VerifyEmail(
            new MysqlIdentityStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
            Resolve::get($c, TransactionManager::class),
            new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
            new SystemClock(),
            Resolve::get($c, AuditTrail::class),
        ));
        $container->singleton(Sessions::class, static fn (Container $c): Sessions => new Sessions(
            new RedisSessionStore(Resolve::get($c, RedisClient::class)),
            new SystemClock(),
        ));

        $container->singleton(SignInStore::class, static fn (Container $c): SignInStore => new MysqlIdentityStore(
            Resolve::get($c, Connection::class),
            Resolve::get($c, Repository::class),
        ));

        $container->singleton(SignIn::class, static function (Container $c): SignIn {
            $hasher = new Argon2idPasswordHasher();

            return new SignIn(
                Resolve::get($c, SignInStore::class),
                $hasher,
                $hasher,
                new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
                new RedisSignInThrottle(Resolve::get($c, RedisClient::class)),
                Resolve::get($c, Sessions::class),
                Resolve::get($c, Logger::class),
                Resolve::get($c, ChallengeGate::class),
                Resolve::get($c, MfaSettings::class),
                Resolve::get($c, CompleteMfaSignIn::class),
                Resolve::get($c, AuditTrail::class),
                Resolve::get($c, SignInAlerts::class),
            );
        });

        $container->singleton(MfaStore::class, static fn (Container $c): MfaStore => new MysqlMfaStore(
            Resolve::get($c, Connection::class),
            Resolve::get($c, Repository::class),
        ));

        $container->singleton(MfaSettings::class, static fn (Container $c): MfaSettings => new MfaSettings(
            Resolve::get($c, MfaStore::class),
            Resolve::get($c, SignInStore::class),
            new Argon2idPasswordHasher(),
            new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
            new SystemClock(),
            self::mfaCrypto($c),
            Resolve::get($c, AuditTrail::class),
            Resolve::get($c, SignInAlerts::class),
        ));

        $container->singleton(CompleteMfaSignIn::class, static fn (Container $c): CompleteMfaSignIn => new CompleteMfaSignIn(
            new RedisMfaChallengeStore(Resolve::get($c, RedisClient::class)),
            Resolve::get($c, MfaStore::class),
            Resolve::get($c, SignInStore::class),
            Resolve::get($c, Sessions::class),
            new SystemClock(),
            Resolve::get($c, Logger::class),
            self::mfaCrypto($c),
            Resolve::get($c, AuditTrail::class),
            Resolve::get($c, SignInAlerts::class),
        ));

        $container->singleton(MfaController::class, static fn (Container $c): MfaController => new MfaController(
            Resolve::get($c, MfaSettings::class),
            Resolve::get($c, Sessions::class),
            Resolve::get($c, SignInStore::class),
        ));

        $container->singleton(AuditLog::class, static fn (Container $c): AuditLog => new MysqlAuditLog(
            Resolve::get($c, Connection::class),
            Resolve::get($c, Repository::class),
            Resolve::get($c, TransactionManager::class),
        ));
        $container->singleton(AuditTrail::class, static fn (Container $c): AuditTrail => new AuditTrail(
            Resolve::get($c, AuditLog::class),
            new SystemClock(),
            Resolve::get($c, Logger::class),
        ));

        $container->singleton(SignInAlerts::class, static fn (Container $c): SignInAlerts => new SignInAlerts(
            new MysqlKnownDevices(Resolve::get($c, Connection::class)),
            Resolve::get($c, SignInStore::class),
            Resolve::get($c, EmailOutbox::class),
            new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
            new SystemClock(),
            Resolve::get($c, Logger::class),
            Resolve::get($c, AuditTrail::class),
        ));

        $container->singleton(HumanVerification::class, static fn (Container $c): HumanVerification => self::humanVerification($c));
        $container->singleton(ChallengeGate::class, static fn (Container $c): ChallengeGate => new ChallengeGate(Resolve::get($c, HumanVerification::class)));
        $container->singleton(ChallengeController::class, static function (Container $c): ChallengeController {
            $key = Resolve::get($c, Configuration::class)->get('identity.turnstile_site_key');

            return new ChallengeController(Resolve::get($c, HumanVerification::class)->enabled() && is_string($key) ? $key : null);
        });
    }

    /**
     * §6.13: MFA keys come from the process environment at use (never the
     * config cache, DP-4). None set = MFA unavailable; production refuses that.
     */
    private static function mfaCrypto(Container $c): ?MfaCrypto
    {
        $ring = KeyRing::fromNamedProcessEnvironment('MFA_SECRET_KEYS', 'MFA_SECRET_ACTIVE_KEY');
        $recovery = getenv('MFA_RECOVERY_KEY');
        $recovery = is_string($recovery) ? $recovery : '';
        if ($ring === null && $recovery === '') {
            if (Resolve::get($c, Configuration::class)->get('app.env') === 'production') {
                throw new \InvalidArgumentException('MFA_SECRET_KEYS, MFA_SECRET_ACTIVE_KEY and MFA_RECOVERY_KEY are required in production.');
            }

            return null;
        }
        $recoveryKey = base64_decode($recovery, true);
        if ($ring === null || $recoveryKey === false) {
            throw new \InvalidArgumentException('MFA_SECRET_KEYS, MFA_SECRET_ACTIVE_KEY and MFA_RECOVERY_KEY must all be set and well-formed.');
        }

        return new MfaCrypto(new SecretCipher($ring), new RecoveryCodes($recoveryKey));
    }

    /** §6.12: Turnstile when both keys are set; off otherwise, never in production. */
    private static function humanVerification(Container $c): HumanVerification
    {
        $config = Resolve::get($c, Configuration::class);
        $site = $config->get('identity.turnstile_site_key');
        $secret = $config->get('identity.turnstile_secret_key');
        $site = is_string($site) ? $site : '';
        $secret = is_string($secret) ? $secret : '';
        if ($site === '' && $secret === '') {
            if ($config->get('app.env') === 'production') {
                throw new \InvalidArgumentException('TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY are required in production.');
            }

            return new DisabledHumanVerification();
        }
        if ($site === '' || $secret === '' || preg_match('/^[A-Za-z0-9_-]{8,128}$/', $site) !== 1) {
            throw new \InvalidArgumentException('TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY must both be set and well-formed.');
        }
        $url = $config->get('app.url');
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException('APP_URL must be an absolute URL for Turnstile hostname checks.');
        }

        return new TurnstileVerification(
            new ProviderHttpClient([TurnstileVerification::endpoint()], connectTimeoutMs: 2_000, totalTimeoutMs: 4_000, maxResponseBytes: 16_384),
            $secret,
            strtolower($host),
            Resolve::get($c, Logger::class),
        );
    }

    private static function breachedPasswordCheck(Container $c): BreachedPasswordCheck
    {
        $config = Resolve::get($c, Configuration::class);
        $mode = $config->get('identity.breached_password_check');
        if ($mode === 'off') {
            if ($config->get('app.env') === 'production') {
                throw new \InvalidArgumentException('BREACHED_PASSWORD_CHECK=off is not allowed in production.');
            }

            return new DisabledBreachedPasswordCheck();
        }
        if ($mode !== 'pwnedpasswords') {
            throw new \InvalidArgumentException('BREACHED_PASSWORD_CHECK must be "pwnedpasswords" or "off".');
        }

        return new PwnedPasswordsRangeCheck(
            // Short timeouts: the check must never make sign-up slow (T-06 fails open).
            new ProviderHttpClient([PwnedPasswordsRangeCheck::endpoint()], connectTimeoutMs: 2_000, totalTimeoutMs: 4_000, maxResponseBytes: 262_144),
            Resolve::get($c, Logger::class),
        );
    }

    public function boot(Application $application): void
    {
    }
}
