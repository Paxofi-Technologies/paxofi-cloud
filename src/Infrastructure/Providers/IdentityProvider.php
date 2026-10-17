<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Providers;

use Paxofi\Core\Application;
use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Container;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\Provider;
use Paxofi\Core\Contracts\RedisClient;
use Paxofi\Core\Contracts\Repository;
use Paxofi\Core\Contracts\TransactionManager;
use PaxofiCloud\Application\Contracts\BreachedPasswordCheck;
use PaxofiCloud\Application\Contracts\EmailOutbox;
use PaxofiCloud\Application\Identity\Registration\RegisterCustomer;
use PaxofiCloud\Application\Identity\Registration\ResendVerification;
use PaxofiCloud\Application\Identity\Registration\VerifyEmail;
use PaxofiCloud\Infrastructure\Container\Resolve;
use PaxofiCloud\Infrastructure\Email\MysqlEmailOutbox;
use PaxofiCloud\Infrastructure\Email\OutboxSealer;
use PaxofiCloud\Infrastructure\Identity\Argon2idPasswordHasher;
use PaxofiCloud\Infrastructure\Identity\MysqlIdentityStore;
use PaxofiCloud\Infrastructure\RateLimit\RedisRateLimiter;
use PaxofiCloud\Infrastructure\Time\SystemClock;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Security\DisabledBreachedPasswordCheck;
use PaxofiCloud\Integration\Security\PwnedPasswordsRangeCheck;

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
            );
        });

        $container->singleton(ResendVerification::class, static fn (Container $c): ResendVerification => new ResendVerification(
            new MysqlIdentityStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
            Resolve::get($c, EmailOutbox::class),
            Resolve::get($c, TransactionManager::class),
            new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
            new SystemClock(),
        ));

        $container->singleton(VerifyEmail::class, static fn (Container $c): VerifyEmail => new VerifyEmail(
            new MysqlIdentityStore(Resolve::get($c, Connection::class), Resolve::get($c, Repository::class)),
            Resolve::get($c, TransactionManager::class),
            new RedisRateLimiter(Resolve::get($c, RedisClient::class)),
            new SystemClock(),
        ));
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
