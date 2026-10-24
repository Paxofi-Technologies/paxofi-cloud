<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Email;

use PaxofiCloud\Domain\Providers\ProviderConnection;

/**
 * The transactional email templates, kept in code so they are reviewed like
 * code (threat model E-8):
 *
 * - Links are built only from the configured application URL, never from a
 *   request's Host header (T-26/M-04).
 * - One-time tokens travel in the URL fragment (#token=…), which browsers do
 *   not send to servers, proxies or Referer headers; the page reads it and
 *   POSTs it.
 * - The only customer-supplied value is the first name. It is reduced to
 *   letters, spaces, apostrophes and hyphens (no URLs or markup can survive)
 *   and HTML-escaped (M-05).
 * - Security alerts (threat model §6.15, T-53) add the device, location, IP
 *   and time of the request; each is checked against a strict pattern
 *   (anything else shows as "Unknown") and escaped. Their only link is the
 *   Security page, where the customer signs in as usual.
 */
final readonly class EmailTemplates
{
    public const string VERIFY_EMAIL = 'verify-email';
    public const string ACCOUNT_EXISTS = 'account-exists';
    public const string SIGN_IN_ALERT = 'sign-in-alert';
    public const string NEW_DEVICE_ALERT = 'new-device-alert';
    public const string SIGN_IN_ATTEMPTS_ALERT = 'sign-in-attempts-alert';
    public const string SECURITY_CHANGE = 'security-change';
    public const string STAFF_INVITATION = 'staff-invitation';
    public const string STAFF_ACTIVATED = 'staff-activated';
    public const string STAFF_ROLES_ADDED = 'staff-roles-added';
    public const string STAFF_SIGN_IN_CODE = 'staff-sign-in-code';
    public const string PROVIDER_CREDENTIAL_CHANGED = 'provider-credential-changed';
    public const string PRICE_SYNC_PROBLEM = 'price-sync-problem';
    public const string STAFF_SECURITY_CHANGE = 'staff-security-change';

    /** Roles the founder bootstrap can add (§6.23), with how they read. */
    private const array ROLE_LABELS = ['catalogue' => 'Catalogue (products, prices and provider costs)'];

    /** Problems the price-sync alert can report (DR-8, DR-9), with how they read. */
    public const array PRICE_SYNC_PROBLEMS = [
        'credential_refused' => 'The provider refused our API credential, so prices cannot be updated until it is fixed. Check the credential under API providers → Domain registrars; for Namecheap, also check that the server IP address entered there is whitelisted in the Namecheap account.',
        'repeated_failures' => 'The price sync has failed three times in a row. The last good prices are still in use.',
        'quarantined' => 'The new price list looked wrong (many extensions missing or large cost changes), so it was held for review. Nothing was changed.',
    ];

    /** Changes the security-change email can report, with how they read. */
    public const array SECURITY_CHANGES = [
        'mfa_enabled' => 'Two-step verification was turned on',
        'mfa_disabled' => 'Two-step verification was turned off',
        'recovery_codes_regenerated' => 'New recovery codes were created; the old ones no longer work',
        'session_revoked' => 'Another device was signed out of your account',
        'other_sessions_revoked' => 'All other devices were signed out of your account',
    ];

    /** §6.25: staff session changes; the member's own action, or a security administrator's. */
    public const array STAFF_CHANGES = [
        'session_revoked' => 'A device was signed out of your admin account',
        'other_sessions_revoked' => 'All other devices were signed out of your admin account',
        'sessions_ended_by_manager' => 'A security administrator signed your admin account out on every device',
    ];

    private const string DISPLAY_TIMEZONE = 'Africa/Lagos';

    private string $appUrl;

    /** Staff invitations link to the admin origin (§6.17); null when the admin console is not configured. */
    private ?string $adminUrl;

    public function __construct(string $appUrl, private string $productName = 'PaxofiCloud', ?string $adminUrl = null)
    {
        $this->appUrl = self::url($appUrl, 'APP_URL');
        $this->adminUrl = $adminUrl === null || $adminUrl === '' ? null : self::url($adminUrl, 'ADMIN_URL');
    }

    /** @return list<string> */
    public static function names(): array
    {
        return [self::VERIFY_EMAIL, self::ACCOUNT_EXISTS, self::SIGN_IN_ALERT, self::NEW_DEVICE_ALERT, self::SIGN_IN_ATTEMPTS_ALERT, self::SECURITY_CHANGE, self::STAFF_INVITATION, self::STAFF_ACTIVATED, self::STAFF_ROLES_ADDED, self::STAFF_SIGN_IN_CODE, self::PROVIDER_CREDENTIAL_CHANGED, self::PRICE_SYNC_PROBLEM, self::STAFF_SECURITY_CHANGE];
    }

    /** @param array<string, string> $parameters */
    public function render(EmailAddress $to, string $template, array $parameters, string $idempotencyKey): EmailMessage
    {
        $name = self::firstName($parameters['name'] ?? '');

        return match ($template) {
            self::VERIFY_EMAIL => $this->verifyEmail($to, $name, self::token($parameters), $idempotencyKey),
            self::ACCOUNT_EXISTS => $this->accountExists($to, $name, $idempotencyKey),
            self::STAFF_INVITATION => $this->staffInvitation($to, $name, self::token($parameters), $idempotencyKey),
            self::STAFF_ACTIVATED => $this->staffActivated($to, $name, self::personName($parameters['staff_name'] ?? ''), $idempotencyKey),
            self::STAFF_ROLES_ADDED => $this->staffRolesAdded($to, $name, $parameters['roles'] ?? '', $idempotencyKey),
            self::STAFF_SIGN_IN_CODE => $this->staffSignInCode($to, $name, $parameters, $idempotencyKey),
            self::PROVIDER_CREDENTIAL_CHANGED => $this->providerCredentialChanged($to, $name, $parameters, $idempotencyKey),
            self::PRICE_SYNC_PROBLEM => $this->priceSyncProblem($to, $name, $parameters, $idempotencyKey),
            self::STAFF_SECURITY_CHANGE => $this->staffSecurityChange($to, $name, $parameters, $idempotencyKey),
            self::SIGN_IN_ALERT, self::NEW_DEVICE_ALERT, self::SIGN_IN_ATTEMPTS_ALERT, self::SECURITY_CHANGE => $this->securityAlert($to, $template, $name, $parameters, $idempotencyKey),
            default => throw new \InvalidArgumentException(sprintf('Unknown email template "%s".', $template)),
        };
    }

    private function verifyEmail(EmailAddress $to, string $name, string $token, string $key): EmailMessage
    {
        $link = $this->appUrl . '/verify-email#token=' . $token;
        $text = <<<TXT
            Hi {$name},

            Confirm your email address to finish setting up your {$this->productName} account:

            {$link}

            This link works once and expires in 24 hours. If you did not create an account, ignore this email.

            {$this->productName}
            TXT;

        return new EmailMessage($to, 'Confirm your email address', $text, $this->html(
            'Confirm your email address',
            sprintf('Hi %s,', self::e($name)),
            sprintf('Confirm your email address to finish setting up your %s account.', self::e($this->productName)),
            'Confirm email address',
            $link,
            'This link works once and expires in 24 hours. If you did not create an account, ignore this email.',
        ), self::VERIFY_EMAIL, $key);
    }

    private function staffInvitation(EmailAddress $to, string $name, string $token, string $key): EmailMessage
    {
        if ($this->adminUrl === null) {
            throw new \InvalidArgumentException('ADMIN_URL is not configured, so staff invitations cannot be sent.');
        }
        $link = $this->adminUrl . '/enrol#token=' . $token;
        $text = <<<TXT
            Hi {$name},

            You have been invited to the {$this->productName} admin console. Set up your sign-in: choose a password and add an authenticator app (such as Google Authenticator or Microsoft Authenticator):

            {$link}

            This link expires in 24 hours. If you did not expect this invitation, ignore this email and tell the security team.

            {$this->productName}
            TXT;

        return new EmailMessage($to, "Your {$this->productName} admin invitation", $text, $this->html(
            "Your {$this->productName} admin invitation",
            sprintf('Hi %s,', self::e($name)),
            sprintf('You have been invited to the %s admin console. Set up your sign-in: choose a password and add an authenticator app (such as Google Authenticator or Microsoft Authenticator).', self::e($this->productName)),
            'Set up sign-in',
            $link,
            'This link expires in 24 hours. If you did not expect this invitation, ignore this email and tell the security team.',
        ), self::STAFF_INVITATION, $key);
    }

    /**
     * Provider connections PC-7: every staff manager hears that a provider
     * credential was replaced. Names the provider and time only, never a value.
     *
     * @param array<string, string> $parameters
     */
    private function providerCredentialChanged(EmailAddress $to, string $name, array $parameters, string $key): EmailMessage
    {
        if ($this->adminUrl === null) {
            throw new \InvalidArgumentException('ADMIN_URL is not configured, so staff alerts cannot be sent.');
        }
        $provider = ProviderConnection::tryFrom($parameters['provider'] ?? '')?->label() ?? 'A provider';
        $time = self::time($parameters['time'] ?? '');
        $link = $this->adminUrl . '/providers';
        $text = <<<TXT
            Hi {$name},

            The API credential for {$provider} was replaced in the {$this->productName} admin console at {$time}.

            If you did not expect this, check the provider connections page now and re-enter the correct credential:

            {$link}

            {$this->productName}
            TXT;

        return new EmailMessage($to, 'A provider credential was changed', $text, $this->html(
            'A provider credential was changed',
            sprintf('Hi %s,', self::e($name)),
            sprintf('The API credential for %s was replaced in the %s admin console at %s.', self::e($provider), self::e($this->productName), self::e($time)),
            'Review provider connections',
            $link,
            'If you did not expect this, check the provider connections page now and re-enter the correct credential.',
        ), self::PROVIDER_CREDENTIAL_CHANGED, $key);
    }

    /**
     * Tells staff managers that a provider price sync needs attention. Names
     * the provider, the problem (a fixed code) and the time; nothing else.
     *
     * @param array<string, string> $parameters
     */
    private function priceSyncProblem(EmailAddress $to, string $name, array $parameters, string $key): EmailMessage
    {
        if ($this->adminUrl === null) {
            throw new \InvalidArgumentException('ADMIN_URL is not configured, so staff alerts cannot be sent.');
        }
        $provider = ProviderConnection::tryFrom($parameters['provider'] ?? '')?->label() ?? 'A provider';
        $problem = self::PRICE_SYNC_PROBLEMS[$parameters['problem'] ?? ''] ?? throw new \InvalidArgumentException('Unknown price sync problem.');
        $time = self::time($parameters['time'] ?? '');
        $link = $this->adminUrl . '/providers';
        $text = <<<TXT
            Hi {$name},

            Price sync for {$provider} needs attention ({$time}):

            {$problem}

            {$link}

            {$this->productName}
            TXT;

        return new EmailMessage($to, 'Price sync needs attention: ' . $provider, $text, $this->html(
            'Price sync needs attention',
            sprintf('Hi %s,', self::e($name)),
            sprintf('Price sync for %s needs attention (%s). %s', self::e($provider), self::e($time), self::e($problem)),
            'Review provider connections',
            $link,
            'Customers keep seeing the last good prices until this is resolved.',
        ), self::PRICE_SYNC_PROBLEM, $key);
    }

    /** Founder bootstrap notice (§6.23): which roles were added, by the operator command, and where to review them. */
    private function staffRolesAdded(EmailAddress $to, string $name, string $roles, string $key): EmailMessage
    {
        if ($this->adminUrl === null) {
            throw new \InvalidArgumentException('ADMIN_URL is not configured, so staff alerts cannot be sent.');
        }
        $labels = [];
        foreach (explode(',', $roles) as $role) {
            $labels[] = self::ROLE_LABELS[$role] ?? throw new \InvalidArgumentException('Unknown staff role.');
        }
        $list = implode(', ', $labels);
        $link = $this->adminUrl . '/staff';
        $text = <<<TXT
            Hi {$name},

            The founder set-up command added these roles to your {$this->productName} staff account: {$list}.

            If you did not run it, review staff access now:

            {$link}

            {$this->productName}
            TXT;

        return new EmailMessage($to, 'Roles were added to your staff account', $text, $this->html(
            'Roles were added to your staff account',
            sprintf('Hi %s,', self::e($name)),
            sprintf('The founder set-up command added these roles to your %s staff account: %s.', self::e($this->productName), self::e($list)),
            'Review staff',
            $link,
            'If you did not run it, review staff access now.',
        ), self::STAFF_ROLES_ADDED, $key);
    }

    /** §6.18: tells the other staff managers that someone finished enrolment, so a stolen invitation is noticed. */
    private function staffActivated(EmailAddress $to, string $name, string $staffName, string $key): EmailMessage
    {
        if ($this->adminUrl === null) {
            throw new \InvalidArgumentException('ADMIN_URL is not configured, so staff alerts cannot be sent.');
        }
        $link = $this->adminUrl . '/staff';
        $text = <<<TXT
            Hi {$name},

            {$staffName} finished setting up their sign-in and can now sign in to the {$this->productName} admin console.

            If you did not expect this, disable this staff member now:

            {$link}

            {$this->productName}
            TXT;

        return new EmailMessage($to, 'A new staff member can now sign in', $text, $this->html(
            'A new staff member can now sign in',
            sprintf('Hi %s,', self::e($name)),
            sprintf('%s finished setting up their sign-in and can now sign in to the %s admin console.', self::e($staffName), self::e($this->productName)),
            'Review staff',
            $link,
            'If you did not expect this, disable this staff member now.',
        ), self::STAFF_ACTIVATED, $key);
    }

    /**
     * §6.20: the third sign-in step. The email names the device, location and
     * IP signing in and where the code may be typed, so a member being phished
     * through a look-alike page has a chance to notice (R-5). No link.
     *
     * @param array<string, string> $parameters
     */
    private function staffSignInCode(EmailAddress $to, string $name, array $parameters, string $key): EmailMessage
    {
        if ($this->adminUrl === null) {
            throw new \InvalidArgumentException('ADMIN_URL is not configured, so staff sign-in codes cannot be sent.');
        }
        $code = $parameters['code'] ?? '';
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw new \InvalidArgumentException('Template parameter "code" is missing or malformed.');
        }
        $host = (string) parse_url($this->adminUrl, PHP_URL_HOST);
        $product = $this->productName;
        $details = [
            'Device' => self::device($parameters['device'] ?? ''),
            'Location' => self::location($parameters['location'] ?? ''),
            'IP address' => self::ip($parameters['ip'] ?? ''),
            'Time' => self::time($parameters['time'] ?? ''),
        ];
        $lines = '';
        foreach ($details as $label => $value) {
            $lines .= "{$label}: {$value}\n";
        }
        $lead = "Someone signed in to the {$product} admin console with your password and authenticator code. Your sign-in code is:";
        $warning = "Only type this code on {$host}. It expires in 10 minutes. If this was not you, do not use the code: someone knows your password and has your authenticator. Tell the security team now.";
        $text = "Hi {$name},\n\n{$lead}\n\n{$code}\n\n{$lines}\n{$warning}\n\n{$product}\n";

        return new EmailMessage($to, "Your {$product} admin sign-in code", $text, $this->html(
            "Your {$product} admin sign-in code",
            sprintf('Hi %s,', self::e($name)),
            self::e($lead),
            '',
            '',
            $warning,
            $details,
            $code,
        ), self::STAFF_SIGN_IN_CODE, $key);
    }

    /**
     * §6.25: a staff member's sessions were ended, by themselves or by a
     * security administrator. Details describe the device that made the change.
     *
     * @param array<string, string> $parameters
     */
    private function staffSecurityChange(EmailAddress $to, string $name, array $parameters, string $key): EmailMessage
    {
        if ($this->adminUrl === null) {
            throw new \InvalidArgumentException('ADMIN_URL is not configured, so staff alerts cannot be sent.');
        }
        $change = $parameters['change'] ?? '';
        $lead = (self::STAFF_CHANGES[$change] ?? throw new \InvalidArgumentException('Template parameter "change" is missing or unknown.')) . '.';
        $product = $this->productName;
        $subject = "Admin security settings changed on your {$product} staff account";
        $byManager = $change === 'sessions_ended_by_manager';
        $footer = $byManager
            ? 'Sign in again when you need to. If you did not expect this, contact your security administrator.'
            : 'If you made this change, you do not need to do anything. If you did not, someone may have your password and authenticator: tell the security team now.';
        $details = [
            ($byManager ? 'Done from' : 'Device') => self::device($parameters['device'] ?? ''),
            'Location' => self::location($parameters['location'] ?? ''),
            'IP address' => self::ip($parameters['ip'] ?? ''),
            'Time' => self::time($parameters['time'] ?? ''),
        ];
        $link = $this->adminUrl . '/account';
        $lines = '';
        foreach ($details as $label => $value) {
            $lines .= "{$label}: {$value}\n";
        }
        $text = "Hi {$name},\n\n{$lead}\n\n{$lines}\n{$footer}\n\nReview your sign-in: {$link}\n\n{$product}\n";

        return new EmailMessage($to, $subject, $text, $this->html(
            $subject,
            sprintf('Hi %s,', self::e($name)),
            self::e($lead),
            'Review your sign-in',
            $link,
            $footer,
            $details,
        ), self::STAFF_SECURITY_CHANGE, $key);
    }

    /** A full name reduced to letters, spaces, apostrophes and hyphens (it came from an invitation form). */
    private static function personName(string $raw): string
    {
        $clean = trim((string) preg_replace("/[^\\p{L}' -]+/u", '', $raw));
        $clean = trim((string) preg_replace('/\\s+/u', ' ', $clean));

        return $clean === '' || mb_strlen($clean) > 100 ? 'A new staff member' : $clean;
    }

    private static function url(string $url, string $name): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        $local = is_array($parts) && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true);
        if (!is_array($parts) || (($parts['scheme'] ?? '') !== 'https' && !$local) || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException($name . ' must be an https URL (http only for localhost).');
        }

        return $url;
    }

    private function accountExists(EmailAddress $to, string $name, string $key): EmailMessage
    {
        $signIn = $this->appUrl . '/sign-in';
        $reset = $this->appUrl . '/reset-password';
        $text = <<<TXT
            Hi {$name},

            Someone tried to create a {$this->productName} account with this email address, but you already have one.

            Sign in: {$signIn}
            Forgot your password? {$reset}

            If this was not you, you can ignore this email; your account has not changed.

            {$this->productName}
            TXT;

        return new EmailMessage($to, 'You already have an account', $text, $this->html(
            'You already have an account',
            sprintf('Hi %s,', self::e($name)),
            sprintf('Someone tried to create a %s account with this email address, but you already have one.', self::e($this->productName)),
            'Sign in',
            $signIn,
            'If this was not you, you can ignore this email; your account has not changed.',
        ), self::ACCOUNT_EXISTS, $key);
    }

    /** @param array<string, string> $parameters */
    private function securityAlert(EmailAddress $to, string $template, string $name, array $parameters, string $key): EmailMessage
    {
        $product = $this->productName;
        [$subject, $lead, $footer] = match ($template) {
            self::SIGN_IN_ALERT => [
                "You signed in to {$product}",
                "Your {$product} account was just signed in to.",
                'If this was you, you do not need to do anything. If it was not, change your password and turn on two-step verification now.',
            ],
            self::NEW_DEVICE_ALERT => [
                "New device signed in to your {$product} account",
                "Your {$product} account was signed in to from a device or browser we have not seen before.",
                'If this was you, you do not need to do anything. If it was not, someone may know your password: change it and turn on two-step verification now.',
            ],
            self::SIGN_IN_ATTEMPTS_ALERT => [
                "Unsuccessful sign-in attempts on your {$product} account",
                sprintf('There were %s unsuccessful attempts to sign in to your %s account in the last hour. The latest one is below.', self::count($parameters['attempts'] ?? ''), $product),
                'Your account is safe as long as your password is not known. If you do not recognise these attempts, change your password and turn on two-step verification.',
            ],
            default => [
                "Security settings changed on your {$product} account",
                (self::SECURITY_CHANGES[$parameters['change'] ?? ''] ?? throw new \InvalidArgumentException('Template parameter "change" is missing or unknown.')) . '.',
                'If you made this change, you do not need to do anything. If you did not, change your password now and contact support.',
            ],
        };
        $details = [
            'Device' => self::device($parameters['device'] ?? ''),
            'Location' => self::location($parameters['location'] ?? ''),
            'IP address' => self::ip($parameters['ip'] ?? ''),
            'Time' => self::time($parameters['time'] ?? ''),
        ];
        $link = $this->appUrl . '/security';
        $lines = '';
        foreach ($details as $label => $value) {
            $lines .= "{$label}: {$value}\n";
        }
        $text = "Hi {$name},\n\n{$lead}\n\n{$lines}\n{$footer}\n\nReview your security settings: {$link}\n\n{$product}\n";

        return new EmailMessage($to, $subject, $text, $this->html(
            $subject,
            sprintf('Hi %s,', self::e($name)),
            self::e($lead),
            'Review security settings',
            $link,
            $footer,
            $details,
        ), $template, $key);
    }

    /** @param array<string, string> $details label => value, escaped here */
    private function html(string $title, string $greeting, string $lead, string $button, string $link, string $footer, array $details = [], string $code = ''): string
    {
        $href = self::e($link);
        $action = $button === ''
            ? ''
            : '<p style="margin:32px 0"><a href="' . $href . '" style="background:#2F6BFF;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none;display:inline-block">' . self::e($button) . '</a></p>'
                . '<p style="font-size:13px;color:#4a5568">Or paste this link into your browser:<br>' . $href . '</p>';
        $codeBlock = $code === '' ? '' : '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;margin:24px 0;font-family:Consolas,Menlo,monospace">' . self::e($code) . '</p>';
        $rows = '';
        foreach ($details as $label => $value) {
            $rows .= '<tr><td style="padding:4px 16px 4px 0;color:#4a5568;white-space:nowrap">' . self::e($label) . '</td><td style="padding:4px 0">' . self::e($value) . '</td></tr>';
        }
        $table = $rows === '' ? '' : '<table role="presentation" style="margin:16px 0;font-size:14px">' . $rows . '</table>';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . self::e($title) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#0B1F3A">'
            . '<table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px">'
            . '<tr><td><p style="font-size:18px;font-weight:bold;margin:0 0 24px">' . self::e($this->productName) . '</p>'
            . '<p>' . $greeting . '</p><p>' . $lead . '</p>' . $codeBlock . $table
            . $action
            . '<p style="font-size:13px;color:#4a5568">' . self::e($footer) . '</p>'
            . '</td></tr></table></body></html>';
    }

    private static function firstName(string $raw): string
    {
        $clean = trim((string) preg_replace("/[^\\p{L}' -]+/u", '', $raw));
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        return $clean === '' || mb_strlen($clean) > 50 ? 'there' : $clean;
    }

    /** @param array<string, string> $parameters */
    private static function token(array $parameters): string
    {
        $token = $parameters['token'] ?? '';
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
            throw new \InvalidArgumentException('Template parameter "token" is missing or malformed.');
        }

        return $token;
    }

    /** e.g. "Chrome 129 on Windows (desktop)" — see DeviceSummary::label(). */
    private static function device(string $value): string
    {
        return preg_match('/^[A-Za-z0-9 .()\/_-]{1,100}$/', $value) === 1 ? $value : 'Unknown';
    }

    /** e.g. "Ikeja, Lagos, Nigeria"; Cloudflare's own approximation. */
    private static function location(string $value): string
    {
        // A dot followed by a letter looks like a domain; mail clients would turn it into a link (T-53).
        return preg_match("/^[\\p{L}\\p{M}\\p{N} ,.'()-]{1,200}\$/u", $value) === 1 && preg_match('/\\.\\p{L}/u', $value) !== 1
            ? $value . ' (approximate)'
            : 'Unknown';
    }

    private static function ip(string $value): string
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : 'Unknown';
    }

    /** ISO 8601 UTC in, Nigerian time out: "18 Oct 2026, 09:43 WAT". */
    private static function time(string $value): string
    {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new \DateTimeZone('UTC'));
        if ($time === false) {
            return 'Unknown';
        }

        return $time->setTimezone(new \DateTimeZone(self::DISPLAY_TIMEZONE))->format('j M Y, H:i') . ' WAT';
    }

    private static function count(string $value): string
    {
        return preg_match('/^[1-9][0-9]{0,5}$/', $value) === 1 ? $value : 'several';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
