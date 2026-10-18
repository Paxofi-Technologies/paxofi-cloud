<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\Registration;

use PaxofiCloud\Application\Email\EmailAddress;
use PaxofiCloud\Application\Identity\Challenge\ChallengeGate;

/**
 * The sign-up form, validated (SRS IAM-001, IAM-003; threat model T-04).
 *
 * - Exactly the documented fields (plus the challenge token, checked by
 *   ChallengeGate): anything else (role, account_id, …) makes
 *   the whole request invalid instead of being silently ignored.
 * - Password: 12-128 characters, any printable Unicode including spaces, no
 *   composition rules (IAM-003). Control characters are refused.
 * - Phone: E.164. Full name: 2-100 printable characters.
 * - The Terms and Privacy Policy must be accepted explicitly (`true`).
 */
final readonly class RegistrationInput
{
    public const array FIELDS = ['full_name', 'email', 'phone', 'password', 'accept_terms', ChallengeGate::FIELD];
    public const int PASSWORD_MIN = 12;
    public const int PASSWORD_MAX = 128;

    private function __construct(
        public string $fullName,
        public EmailAddress $email,
        public string $phone,
        #[\SensitiveParameter] public string $password,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data decoded JSON object
     * @throws InvalidRegistration
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                $errors[is_string($key) && preg_match('/^[a-z_]{1,32}$/', $key) === 1 ? $key : '_unknown'] = 'unknown_field';
            }
        }

        $fullName = $data['full_name'] ?? null;
        if (!is_string($fullName)) {
            $errors['full_name'] = 'required';
        } else {
            $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');
            $length = mb_strlen($fullName, 'UTF-8');
            if (!mb_check_encoding($fullName, 'UTF-8') || $length < 2 || $length > 100 || preg_match('/\p{C}/u', $fullName) === 1) {
                $errors['full_name'] = 'invalid';
            }
        }

        $email = null;
        $rawEmail = $data['email'] ?? null;
        if (!is_string($rawEmail)) {
            $errors['email'] = 'required';
        } else {
            try {
                $email = new EmailAddress($rawEmail);
            } catch (\InvalidArgumentException) {
                $errors['email'] = 'invalid';
            }
        }

        $phone = $data['phone'] ?? null;
        if (!is_string($phone)) {
            $errors['phone'] = 'required';
        } elseif (preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) !== 1) {
            $errors['phone'] = 'invalid_e164';
        }

        $password = $data['password'] ?? null;
        if (!is_string($password)) {
            $errors['password'] = 'required';
        } elseif (!mb_check_encoding($password, 'UTF-8') || preg_match('/\p{Cc}/u', $password) === 1) {
            $errors['password'] = 'invalid_characters';
        } elseif (mb_strlen($password, 'UTF-8') < self::PASSWORD_MIN) {
            $errors['password'] = 'too_short';
        } elseif (mb_strlen($password, 'UTF-8') > self::PASSWORD_MAX) {
            $errors['password'] = 'too_long';
        }

        if (($data['accept_terms'] ?? null) !== true) {
            $errors['accept_terms'] = 'must_accept';
        }

        if ($errors !== [] || !is_string($fullName) || !is_string($phone) || !is_string($password) || $email === null) {
            throw new InvalidRegistration($errors);
        }

        return new self($fullName, $email, $phone, $password);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['email_hash' => $this->email->hash(), 'password' => '[redacted]'];
    }
}
