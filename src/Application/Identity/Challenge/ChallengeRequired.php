<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Challenge;

/** The request needs a valid challenge token and did not carry one. */
final class ChallengeRequired extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A bot challenge is required.');
    }
}
