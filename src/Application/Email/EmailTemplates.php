<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Email;

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
 */
final readonly class EmailTemplates
{
    public const string VERIFY_EMAIL = 'verify-email';
    public const string ACCOUNT_EXISTS = 'account-exists';

    private string $appUrl;

    public function __construct(string $appUrl, private string $productName = 'PaxofiCloud')
    {
        $appUrl = rtrim($appUrl, '/');
        $parts = parse_url($appUrl);
        $local = is_array($parts) && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true);
        if (!is_array($parts) || (($parts['scheme'] ?? '') !== 'https' && !$local) || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('APP_URL must be an https URL (http only for localhost).');
        }
        $this->appUrl = $appUrl;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return [self::VERIFY_EMAIL, self::ACCOUNT_EXISTS];
    }

    /** @param array<string, string> $parameters */
    public function render(EmailAddress $to, string $template, array $parameters, string $idempotencyKey): EmailMessage
    {
        $name = self::firstName($parameters['name'] ?? '');

        return match ($template) {
            self::VERIFY_EMAIL => $this->verifyEmail($to, $name, self::token($parameters), $idempotencyKey),
            self::ACCOUNT_EXISTS => $this->accountExists($to, $name, $idempotencyKey),
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

    private function html(string $title, string $greeting, string $lead, string $button, string $link, string $footer): string
    {
        $href = self::e($link);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . self::e($title) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#0B1F3A">'
            . '<table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px">'
            . '<tr><td><p style="font-size:18px;font-weight:bold;margin:0 0 24px">' . self::e($this->productName) . '</p>'
            . '<p>' . $greeting . '</p><p>' . $lead . '</p>'
            . '<p style="margin:32px 0"><a href="' . $href . '" style="background:#2F6BFF;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none;display:inline-block">' . self::e($button) . '</a></p>'
            . '<p style="font-size:13px;color:#4a5568">Or paste this link into your browser:<br>' . $href . '</p>'
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

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
