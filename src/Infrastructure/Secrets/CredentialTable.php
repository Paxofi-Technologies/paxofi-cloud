<?php

declare(strict_types=1);

namespace PaxofiCloud\Infrastructure\Secrets;

/**
 * Where encrypted credentials live. Payment gateway keys have their own table
 * under their own key ring (THREAT-MODEL-PAYMENTS.md PY-3), so rotating or
 * listing provider credentials never touches them, and a key ID from one ring
 * is never read against the other. The table name only ever comes from here.
 */
enum CredentialTable: string
{
    case Providers = 'provider_credentials';
    case PaymentGateways = 'payment_gateway_credentials';
}
