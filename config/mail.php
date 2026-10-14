<?php

declare(strict_types=1);

use Paxofi\Core\Configuration\Environment;

// Non-secret mail settings (threat model THREAT-MODEL-TRANSACTIONAL-EMAIL).
// Provider tokens and the outbox secret key are deliberately NOT here: they
// are read from the worker's process environment at the moment of use, so
// they never land in a cached configuration file.
return static fn (Environment $env): array => [
    'provider' => $env->get('EMAIL_PROVIDER', 'postmark'),
    'from_name' => $env->get('EMAIL_FROM_NAME', 'PaxofiCloud'),
    'from_address' => $env->get('EMAIL_FROM_ADDRESS', 'no-reply@paxofi.com'),
    // Outside production only these recipients (addresses or @domains) are
    // ever emailed (E-9).
    'recipient_allowlist' => $env->get('EMAIL_RECIPIENT_ALLOWLIST', ''),
    'hourly_cap' => $env->integer('EMAIL_HOURLY_CAP', 500),
];
