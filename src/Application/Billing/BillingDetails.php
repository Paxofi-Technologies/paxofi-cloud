<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Billing;

use PaxofiCloud\Application\Audit\Countries;
use PaxofiCloud\Domain\Money\Currency;

/**
 * An account's billing details (THREAT-MODEL-COMMERCE.md CD-21, SRS ACC-004).
 * Every field is plain text with a length limit; control characters, angle
 * brackets and line breaks are refused, because these values are printed on
 * invoices and shown in the admin console (C-39).
 */
final readonly class BillingDetails
{
    public const array FIELDS = ['full_name', 'company', 'address_line1', 'address_line2', 'city', 'region', 'postcode', 'country', 'phone', 'tin'];

    private function __construct(
        public string $fullName,
        public ?string $company,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public string $region,
        public ?string $postcode,
        public string $country,
        public string $phone,
        public ?string $tin,
    ) {
    }

    /**
     * @param array<array-key, mixed> $input exactly the FIELDS keys
     * @throws InvalidBillingDetails with one message per bad field
     */
    public static function fromInput(array $input): self
    {
        $errors = [];
        $unknown = array_diff(array_map('strval', array_keys($input)), self::FIELDS);
        if ($unknown !== []) {
            throw new InvalidBillingDetails(['form' => 'Unexpected fields.']);
        }
        $text = static function (string $field, int $max, bool $required) use ($input, &$errors): ?string {
            $value = $input[$field] ?? null;
            if ($value === null || $value === '') {
                if ($required) {
                    $errors[$field] = 'Required.';
                }

                return null;
            }
            if (!is_string($value)) {
                $errors[$field] = 'Must be text.';

                return null;
            }
            $value = trim($value);
            if ($value === '') {
                if ($required) {
                    $errors[$field] = 'Required.';
                }

                return null;
            }
            if (mb_check_encoding($value, 'UTF-8') === false || preg_match('/[\p{C}<>]/u', $value) !== 0) {
                $errors[$field] = 'Contains characters that are not allowed.';

                return null;
            }
            if (mb_strlen($value) > $max) {
                $errors[$field] = "Use {$max} characters or fewer.";

                return null;
            }

            return $value;
        };

        $fullName = $text('full_name', 100, true);
        $company = $text('company', 100, false);
        $line1 = $text('address_line1', 120, true);
        $line2 = $text('address_line2', 120, false);
        $city = $text('city', 80, true);
        $region = $text('region', 80, true);
        $postcode = $text('postcode', 20, false);
        if ($postcode !== null && preg_match('/^[A-Za-z0-9 -]{1,20}$/', $postcode) !== 1) {
            $errors['postcode'] = 'Use letters, digits, spaces and hyphens only.';
        }
        $country = $text('country', 2, true);
        if ($country !== null && (preg_match('/^[A-Z]{2}$/', $country) !== 1 || !Countries::known($country))) {
            $errors['country'] = 'Choose a country from the list.';
        }
        $phone = $text('phone', 16, true);
        if ($phone !== null && preg_match('/^\+[1-9][0-9]{6,14}$/', $phone) !== 1) {
            $errors['phone'] = 'Use the international format, e.g. +2348012345678.';
        }
        $tin = $text('tin', 32, false);
        if ($tin !== null && preg_match('#^[A-Za-z0-9/-]{1,32}$#', $tin) !== 1) {
            $errors['tin'] = 'Use letters, digits, hyphens and slashes only.';
        }

        if ($errors !== [] || $fullName === null || $line1 === null || $city === null || $region === null || $country === null || $phone === null) {
            throw new InvalidBillingDetails($errors === [] ? ['form' => 'Required fields are missing.'] : $errors);
        }

        return new self($fullName, $company, $line1, $line2, $city, $region, $postcode, $country, $phone, $tin);
    }

    /** Rebuilds stored values without re-validating (they were validated when saved). */
    public static function stored(string $fullName, ?string $company, string $line1, ?string $line2, string $city, string $region, ?string $postcode, string $country, string $phone, ?string $tin): self
    {
        return new self($fullName, $company, $line1, $line2, $city, $region, $postcode, $country, $phone, $tin);
    }

    /** NGN for Nigeria, USD for everyone else (BR-02, PRC-003). */
    public function defaultCurrency(): Currency
    {
        return $this->country === 'NG' ? Currency::NGN : Currency::USD;
    }

    /** @return array<string, ?string> the public field map, same keys as FIELDS */
    public function toArray(): array
    {
        return [
            'full_name' => $this->fullName,
            'company' => $this->company,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'city' => $this->city,
            'region' => $this->region,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'phone' => $this->phone,
            'tin' => $this->tin,
        ];
    }

    /** @return list<string> names of fields whose value differs from $other (for the audit log; never values) */
    public function changedFields(?self $other): array
    {
        if ($other === null) {
            return self::FIELDS;
        }
        $before = $other->toArray();

        return array_keys(array_filter($this->toArray(), static fn (?string $v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    }
}
