<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider\Http;

use UnexpectedValueException;

/** The provider answered, but not in the documented shape. Never retried blindly. */
final class MalformedProviderResponse extends UnexpectedValueException
{
}
