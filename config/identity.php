<?php

declare(strict_types=1);

use Paxofi\Core\Configuration\Environment;

// Customer identity settings (SRS IAM-001..003).
return static fn (Environment $env): array => [
    // Version of the Terms of Service and Privacy Policy a new customer
    // accepts; stored on the identity. Bump it when either document changes.
    'terms_version' => $env->get('TERMS_VERSION', '2026-10-01'),
    // Breached-password check at sign-up (T-06): "pwnedpasswords" or "off".
    // "off" is refused in production; tests and offline development use it.
    'breached_password_check' => $env->get('BREACHED_PASSWORD_CHECK', 'pwnedpasswords'),
];
