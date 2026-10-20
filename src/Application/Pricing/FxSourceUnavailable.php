<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Pricing;

/** The rate source failed or answered with something unusable; the message is a fixed description, never a response body. */
final class FxSourceUnavailable extends \RuntimeException
{
}
