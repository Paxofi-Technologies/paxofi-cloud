<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Provider;

interface ProviderAdapter
{
    public function providerName(): string;
}
