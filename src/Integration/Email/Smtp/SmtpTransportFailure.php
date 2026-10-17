<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email\Smtp;

/** Network, TLS or timeout failure. Messages are fixed text, never server output. */
final class SmtpTransportFailure extends \RuntimeException
{
}
