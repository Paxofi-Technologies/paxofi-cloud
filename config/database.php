<?php

declare(strict_types=1);

use Paxofi\Core\Configuration\Environment;

// Keys read by Paxofi\Core\Infrastructure\Provider (PCF reference §4).
return static fn (Environment $env): array => [
    'dsn' => $env->require('DB_DSN'),
    'username' => $env->require('DB_USERNAME'),
    'password' => $env->require('DB_PASSWORD'),
];
