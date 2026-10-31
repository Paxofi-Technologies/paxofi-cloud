<?php

declare(strict_types=1);

use Paxofi\Core\Configuration\Environment;

return static fn (Environment $env): array => [
    'name' => 'PaxofiCloud',
    'env' => $env->name(),
    // Never true outside development; the problem-details handler only reveals
    // exception messages when this is on.
    'debug' => $env->isDevelopment() && $env->boolean('APP_DEBUG', false),
    'url' => $env->get('APP_URL', 'http://127.0.0.1:8080'),
    // The staff admin origin (WebAuthn relying party, §6.17). Empty = staff sign-in off.
    'admin_url' => $env->get('ADMIN_URL', ''),
    'max_body_bytes' => $env->integer('APP_MAX_BODY_BYTES', 1_048_576),
    // 'payments-web' serves only the gateway webhooks (payments threat model PY-7); anything else is the web tier.
    'role' => $env->get('APP_ROLE', 'web'),
];
