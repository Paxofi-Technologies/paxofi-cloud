<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email\Smtp;

interface SmtpConnector
{
    /** Opens a connection; for port 465 the channel is already encrypted. */
    public function connect(SmtpServer $server): SmtpChannel;
}
