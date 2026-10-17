<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Application\Identity\Registration;

use PaxofiCloud\Application\Identity\Registration\InvalidRegistration;
use PaxofiCloud\Application\Identity\Registration\RegistrationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SRS IAM-001, IAM-003; threat model T-04 (RegistrationSchemaTest). */
final class RegistrationInputTest extends TestCase
{
    public function testAcceptsAValidSubmission(): void
    {
        $input = RegistrationInput::fromArray(self::valid());

        self::assertSame('Ada Okafor', $input->fullName);
        self::assertSame('ada@example.ng', $input->email->value);
        self::assertSame('+2348031234567', $input->phone);
    }

    public function testRejectsUnknownFieldsSuchAsRoleOrAccount(): void
    {
        $errors = self::errors(self::valid() + ['role' => 'admin', 'account_id' => 'x', 'Bad Key!' => 1]);

        self::assertSame('unknown_field', $errors['role']);
        self::assertSame('unknown_field', $errors['account_id']);
        self::assertSame('unknown_field', $errors['_unknown'], 'Odd keys are never echoed back');
    }

    /** @return iterable<string, array{string, int}> */
    public static function passwordLengths(): iterable
    {
        yield '11 is too short' => [str_repeat('a', 11), 0];
        yield '12 is the minimum' => [str_repeat('a', 12), 1];
        yield '128 is the maximum' => [str_repeat('a', 128), 1];
        yield '129 is too long' => [str_repeat('a', 129), 0];
        yield 'length counts characters, not bytes' => [str_repeat('é', 12), 1];
        yield 'spaces and emoji are allowed' => ['correct horse 🐴 battery', 1];
    }

    #[DataProvider('passwordLengths')]
    public function testPasswordPolicyHasLengthLimitsAndNoCompositionRules(string $password, int $ok): void
    {
        $data = ['password' => $password] + self::valid();
        if ($ok === 1) {
            self::assertSame($password, RegistrationInput::fromArray($data)->password);
        } else {
            self::assertContains(self::errors($data)['password'], ['too_short', 'too_long']);
        }
    }

    public function testPasswordWithControlCharactersOrInvalidUtf8IsRefused(): void
    {
        self::assertSame('invalid_characters', self::errors(['password' => "abcdefghijkl\x00m"] + self::valid())['password']);
        self::assertSame('invalid_characters', self::errors(['password' => "abcdefghijkl\xff\xfe"] + self::valid())['password']);
    }

    /** @return iterable<string, array{string}> */
    public static function badPhones(): iterable
    {
        yield 'no plus' => ['2348031234567'];
        yield 'local format' => ['08031234567'];
        yield 'too long' => ['+1234567890123456'];
        yield 'letters' => ['+234803ABC4567'];
        yield 'leading zero country' => ['+0348031234567'];
    }

    #[DataProvider('badPhones')]
    public function testPhoneMustBeE164(string $phone): void
    {
        self::assertSame('invalid_e164', self::errors(['phone' => $phone] + self::valid())['phone']);
    }

    public function testTermsMustBeAcceptedWithARealBoolean(): void
    {
        self::assertSame('must_accept', self::errors(['accept_terms' => false] + self::valid())['accept_terms']);
        self::assertSame('must_accept', self::errors(['accept_terms' => 'true'] + self::valid())['accept_terms']);
    }

    public function testFullNameIsNormalisedAndControlCharactersRefused(): void
    {
        self::assertSame('Ada Okafor', RegistrationInput::fromArray(['full_name' => "  Ada \t  Okafor "] + self::valid())->fullName);
        self::assertSame('invalid', self::errors(['full_name' => "Ada\u{202E}Okafor"] + self::valid())['full_name']);
        self::assertSame('invalid', self::errors(['full_name' => 'A'] + self::valid())['full_name']);
    }

    public function testMissingAndWronglyTypedFieldsAreReportedTogether(): void
    {
        $errors = self::errors(['email' => 42]);

        self::assertSame(['full_name' => 'required', 'email' => 'required', 'phone' => 'required', 'password' => 'required', 'accept_terms' => 'must_accept'], $errors);
    }

    public function testDebugOutputHidesThePasswordAndAddress(): void
    {
        $dump = print_r(RegistrationInput::fromArray(self::valid()), true);

        self::assertStringNotContainsString('Sup3r secret passphrase', $dump);
        self::assertStringNotContainsString('ada@example.ng', $dump);
    }

    /** @return array<string, mixed> */
    public static function valid(): array
    {
        return ['full_name' => 'Ada Okafor', 'email' => 'ada@example.ng', 'phone' => '+2348031234567', 'password' => 'Sup3r secret passphrase', 'accept_terms' => true];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, string>
     */
    private static function errors(array $data): array
    {
        try {
            RegistrationInput::fromArray($data);
        } catch (InvalidRegistration $e) {
            return $e->errors;
        }
        self::fail('Expected the input to be rejected.');
    }
}
