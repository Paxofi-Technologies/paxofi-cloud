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
    public const string SIGN_IN_NEW_DEVICE = 'auth.sign_in.new_device';
    public const string MFA_SUCCEEDED = 'auth.mfa.succeeded';
    public const string MFA_FAILED = 'auth.mfa.failed';
    public const string MFA_LOCKED = 'auth.mfa.locked';
    public const string SIGNED_OUT = 'auth.signed_out';
    public const string SESSION_REVOKED = 'auth.session.revoked';
    public const string SESSIONS_REVOKED_OTHERS = 'auth.sessions.revoked_others';
    public const string MFA_ENABLED = 'mfa.enabled';
    public const string MFA_DISABLED = 'mfa.disabled';
    public const string RECOVERY_CODES_REGENERATED = 'mfa.recovery_codes_regenerated';
    public const string MFA_CHANGE_REFUSED = 'mfa.change_refused';
    public const string STAFF_PERMISSION_DENIED = 'staff.permission_denied';
    public const string STAFF_ROLE_GRANTED = 'staff.role.granted';
    public const string STAFF_ROLE_REQUESTED = 'staff.role.requested';
    public const string STAFF_ROLE_APPROVED = 'staff.role.approved';
    public const string STAFF_ROLE_REVOKED = 'staff.role.revoked';
    public const string STAFF_ROLE_CHANGE_REFUSED = 'staff.role.change_refused';
    public const string STAFF_INVITED = 'staff.invited';
    public const string STAFF_BOOTSTRAP_INVITED = 'staff.bootstrap_invited';
    public const string STAFF_INVITATION_REFUSED = 'staff.invitation_refused';
    public const string STAFF_PASSKEY_REGISTERED = 'staff.passkey.registered';
    public const string STAFF_PASSKEY_REJECTED = 'staff.passkey.rejected';
    public const string STAFF_ACTIVATED = 'staff.activated';
    public const string STAFF_SIGNED_IN = 'staff.signed_in';
    public const string STAFF_SIGN_IN_FAILED = 'staff.sign_in.failed';
    public const string STAFF_PASSKEY_CLONE_SUSPECTED = 'staff.passkey.clone_suspected';
    public const string STAFF_SIGNED_OUT = 'staff.signed_out';
    public const string STAFF_PASSWORD_ACCEPTED = 'staff.sign_in.password_accepted';
    public const string STAFF_CODE_ACCEPTED = 'staff.sign_in.code_accepted';
    public const string STAFF_EMAIL_CODE_SENT = 'staff.sign_in.email_code_sent';
    public const string STAFF_SIGN_IN_SET_UP = 'staff.sign_in.set_up';
    public const string STAFF_SIGN_IN_SETUP_REFUSED = 'staff.sign_in.setup_refused';
    // Provider connections (threat model PC-7): IDs and fixed codes only, never a value.
    public const string PROVIDER_CREDENTIAL_SUBMITTED = 'provider_credential.submitted';
    public const string PROVIDER_CREDENTIAL_REFUSED = 'provider_credential.refused';
    public const string PROVIDER_CREDENTIAL_STORED = 'provider_credential.stored';
    public const string PROVIDER_CREDENTIAL_REJECTED = 'provider_credential.rejected';
    public const string REGISTRAR_WRITE_REFUSED = 'registrar.write_refused';
    public const string PROVIDER_PRICES_SYNCED = 'provider_prices.synced';
    public const string PROVIDER_PRICES_QUARANTINED = 'provider_prices.quarantined';
    public const string PROVIDER_PRICES_SYNC_FAILED = 'provider_prices.sync_failed';
    public const string PROVIDER_PRICES_SYNC_REQUESTED = 'provider_prices.sync_requested';
    public const string PROVIDER_PRICES_SYNC_REQUEST_REFUSED = 'provider_prices.sync_request_refused';

    private function __construct()
    {
    }
}
