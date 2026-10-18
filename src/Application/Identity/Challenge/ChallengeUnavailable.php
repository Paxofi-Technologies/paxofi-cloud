<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Challenge;

/** The challenge could not be verified right now; the request is refused (fail closed). */
final class ChallengeUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The bot challenge cannot be verified right now.');
    }
}
