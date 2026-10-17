<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Email;

use Paxofi\Core\Contracts\Configuration;
use Paxofi\Core\Contracts\Connection;
use Paxofi\Core\Contracts\Logger;
use Paxofi\Core\Contracts\Repository;
use PaxofiCloud\Application\Contracts\TransactionalEmailSender;
use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Email\EmailTemplates;
use PaxofiCloud\Application\Email\FailoverEmailSender;
use PaxofiCloud\Infrastructure\Time\SystemClock;
use PaxofiCloud\Integration\Email\PostmarkEmailSender;
use PaxofiCloud\Integration\Email\ResendEmailSender;
use PaxofiCloud\Integration\Email\Sender;
use PaxofiCloud\Integration\Email\Smtp\SmtpEmailSender;
use PaxofiCloud\Integration\Email\Smtp\SmtpServer;
use PaxofiCloud\Integration\Email\Smtp\StreamSmtpConnector;
use PaxofiCloud\Integration\Provider\Http\ProviderHttpClient;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * Builds the mail worker from configuration plus secrets read from the
 * worker's process environment only (threat model E-6):
 *   EMAIL_OUTBOX_PUBLIC_KEY, EMAIL_OUTBOX_SECRET_KEY, POSTMARK_SERVER_TOKEN,
 *   RESEND_API_KEY, SMTP_HOST/SMTP_PORT/SMTP_USERNAME/SMTP_PASSWORD (each
 *   provider is optional; at least one is required).
 */
final class MailWorkerFactory
{
    /** @param \Closure(string): (string|false) $getenv */
    public static function create(Configuration $config, Connection $connection, Repository $repository, Logger $logger, ?\Closure $getenv = null): EmailWorker
    {
        $getenv ??= static fn (string $name): string|false => getenv($name);
        $clock = new SystemClock();
        $sealer = OutboxSealer::forOpening(self::required($getenv, 'EMAIL_OUTBOX_PUBLIC_KEY'), self::required($getenv, 'EMAIL_OUTBOX_SECRET_KEY'));

        return new EmailWorker(
            new MysqlEmailOutbox($connection, $repository, $sealer, $clock),
            $sealer,
            new EmailTemplates(self::string($config, 'app.url')),
            self::sender($config, $getenv),
            RecipientPolicy::fromEnvironment(self::string($config, 'app.env'), self::string($config, 'mail.recipient_allowlist')),
            $clock,
            $logger,
            self::int($config, 'mail.hourly_cap'),
        );
    }

    /** @param \Closure(string): (string|false) $getenv */
    public static function sender(Configuration $config, \Closure $getenv): TransactionalEmailSender
    {
        $from = new Sender(self::string($config, 'mail.from_name'), new EmailAddress(self::string($config, 'mail.from_address')));
        $transport = new ProviderHttpClient([PostmarkEmailSender::endpoint(), ResendEmailSender::endpoint()]);

        $senders = [];
        $postmark = $getenv('POSTMARK_SERVER_TOKEN');
        if (is_string($postmark) && $postmark !== '') {
            $senders['postmark'] = new PostmarkEmailSender($transport, new ProviderCredential('postmark-server', $postmark), $from);
        }
        $resend = $getenv('RESEND_API_KEY');
        if (is_string($resend) && $resend !== '') {
            $senders['resend'] = new ResendEmailSender($transport, new ProviderCredential('resend-sending', $resend), $from);
        }

        $smtpHost = $getenv('SMTP_HOST');
        $smtpPassword = $getenv('SMTP_PASSWORD');
        if (is_string($smtpHost) && $smtpHost !== '' && is_string($smtpPassword) && $smtpPassword !== '') {
            $port = $getenv('SMTP_PORT');
            $username = $getenv('SMTP_USERNAME');
            $senders['smtp'] = new SmtpEmailSender(
                new StreamSmtpConnector(),
                new SmtpServer(strtolower($smtpHost), is_string($port) && $port !== '' ? self::port($port) : SmtpServer::STARTTLS_PORT),
                is_string($username) && $username !== '' ? $username : $from->address->value,
                new ProviderCredential('smtp-relay', $smtpPassword),
                $from,
            );
        }

        $primaryName = self::string($config, 'mail.provider');
        if (!in_array($primaryName, ['postmark', 'resend', 'smtp'], true)) {
            throw new \InvalidArgumentException('EMAIL_PROVIDER must be "postmark", "resend" or "smtp".');
        }
        $primary = $senders[$primaryName] ?? null;
        unset($senders[$primaryName]);
        $secondary = $senders === [] ? null : array_values($senders)[0];
        if ($primary === null) {
            if ($secondary === null) {
                throw new \InvalidArgumentException('No email provider credentials: set POSTMARK_SERVER_TOKEN, RESEND_API_KEY or SMTP_HOST plus SMTP_PASSWORD.');
            }
            // The chosen primary has no key yet: run on the other one alone.
            return $secondary;
        }

        return new FailoverEmailSender($primary, $secondary);
    }

    private static function port(string $value): int
    {
        if (preg_match('/^[0-9]{1,5}$/', $value) !== 1) {
            throw new \InvalidArgumentException('SMTP_PORT must be a number.');
        }

        return (int) $value;
    }

    /** @param \Closure(string): (string|false) $getenv */
    private static function required(\Closure $getenv, string $name): string
    {
        $value = $getenv($name);
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException($name . ' must be set for the mail worker.');
        }

        return $value;
    }

    private static function string(Configuration $config, string $key): string
    {
        $value = $config->get($key);
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Configuration "%s" must be a string.', $key));
        }

        return $value;
    }

    private static function int(Configuration $config, string $key): int
    {
        $value = $config->get($key);
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Configuration "%s" must be an integer.', $key));
        }

        return $value;
    }
}
