<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Audit;

/** The audited security events (threat model §6.14). Names are stable: reports and alerts key on them. */
final class AuditEventType
{
    public const string REGISTERED = 'identity.registered';
    public const string REGISTRATION_REPEATED = 'identity.registration_repeated';
    public const string REGISTRATION_EXISTING_ACCOUNT = 'identity.registration_existing_account';
    public const string EMAIL_VERIFIED = 'identity.email_verified';
    public const string VERIFICATION_RESENT = 'identity.verification_resent';
    public const string SIGN_IN_SUCCEEDED = 'auth.sign_in.succeeded';
    public const string SIGN_IN_FAILED = 'auth.sign_in.failed';
    public const string SIGN_IN_MFA_REQUIRED = 'auth.sign_in.mfa_required';
    public const string SIGN_IN_MANY_FAILURES = 'auth.sign_in.many_failures';
    public const string MFA_SUCCEEDED = 'auth.mfa.succeeded';
    public const string MFA_FAILED = 'auth.mfa.failed';
    public const string MFA_LOCKED = 'auth.mfa.locked';
    public const string SIGNED_OUT = 'auth.signed_out';
    public const string MFA_ENABLED = 'mfa.enabled';
    public const string MFA_DISABLED = 'mfa.disabled';
    public const string RECOVERY_CODES_REGENERATED = 'mfa.recovery_codes_regenerated';
    public const string MFA_CHANGE_REFUSED = 'mfa.change_refused';

    private function __construct()
    {
    }
}
