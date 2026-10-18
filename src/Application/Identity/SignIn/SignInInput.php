<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Identity\SignIn;

use PaxofiCloud\Application\Email\EmailAddress;

/**
 * The sign-in form: exactly `email` and `password`. Only shape is checked here;
 * a password that could never have been registered (too short, say) simply
 * fails verification, so the response does not hint at the policy. The upper
 * bound stops oversized input reaching Argon2id (T-11).
 */
final readonly class SignInInput
{
    public const array FIELDS = ['email', 'password'];
    public const int PASSWORD_MAX_BYTES = 1024;

    private function __construct(public EmailAddress $email, #[\SensitiveParameter] public string $password)
    {
    }

    /**
     * @param array<array-key, mixed> $data decoded JSON object
     * @throws InvalidSignIn
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                $errors[is_string($key) && preg_match('/^[a-z_]{1,32}$/', $key) === 1 ? $key : '_unknown'] = 'unknown_field';
            }
        }

        $email = null;
        $rawEmail = $data['email'] ?? null;
        if (!is_string($rawEmail) || $rawEmail === '') {
            $errors['email'] = 'required';
        } else {
            try {
                $email = new EmailAddress($rawEmail);
            } catch (\InvalidArgumentException) {
                $errors['email'] = 'invalid';
            }
        }

        $password = $data['password'] ?? null;
        if (!is_string($password) || $password === '') {
            $errors['password'] = 'required';
        } elseif (strlen($password) > self::PASSWORD_MAX_BYTES) {
            $errors['password'] = 'too_long';
        }

        if ($errors !== [] || !is_string($password)) {
            throw new InvalidSignIn($errors);
        }

        return new self($email, $password);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['email_hash' => $this->email->hash(), 'password' => '[redacted]'];
    }
}
