<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

/**
 * Plain-text checks for catalogue names, descriptions and labels (threat
 * model CD-11, C-11): no control characters or angle brackets, bounded length.
 * Frontends render these as text; this keeps them sane at the source.
 */
final class CatalogueText
{
    /** @throws CatalogueRuleViolation */
    public static function name(string $value, string $field): string
    {
        return self::check($value, $field, 120, false);
    }

    /** @throws CatalogueRuleViolation */
    public static function description(string $value, string $field): string
    {
        return self::check($value, $field, 1000, true);
    }

    /** @throws CatalogueRuleViolation */
    public static function slug(string $value, string $field): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $value) !== 1) {
            throw new CatalogueRuleViolation('invalid_slug', $field);
        }

        return $value;
    }

    /** @throws CatalogueRuleViolation */
    private static function check(string $value, string $field, int $max, bool $allowEmpty): string
    {
        $value = trim($value);
        if (($value === '' && !$allowEmpty) || mb_strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new CatalogueRuleViolation('invalid_length', $field);
        }
        if (preg_match('/[\p{Cc}\p{Cf}<>]/u', $value) === 1) {
            throw new CatalogueRuleViolation('invalid_characters', $field);
        }

        return $value;
    }
}
