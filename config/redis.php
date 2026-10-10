<?php

declare(strict_types=1);

use Paxofi\Core\Configuration\Environment;

// Values must be ints, not numeric strings (PCF reference §4).
$redis = static fn (Environment $env): array => [
    'host' => $env->require('REDIS_HOST'),
    'port' => $env->integer('REDIS_PORT', 6379),
    'database' => $env->integer('REDIS_DATABASE', 0),
];

return static function (Environment $env) use ($redis): array {
    $config = $redis($env);
    $password = $env->get('REDIS_PASSWORD');
    if ($password !== null && $password !== '') {
        $config['password'] = $password;
    }

    return $config;
};
