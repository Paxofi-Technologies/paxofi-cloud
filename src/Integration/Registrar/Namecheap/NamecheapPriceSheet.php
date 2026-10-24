<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\Namecheap;

use PaxofiCloud\Domain\Pricing\CostCurrency;
use PaxofiCloud\Domain\Registrar\CostAmount;
use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;

/**
 * One `namecheap.users.getPricing` response for one action, read as a stream
 * (THREAT-MODEL-DOMAIN-PRICING.md NC-4 to NC-7).
 *
 * - No DOCTYPE, no entities, no network: a response that declares either is
 *   refused before parsing, and the reader never substitutes entities.
 * - Depth is capped; at most MAX_PRODUCTS products are read.
 * - Only the tiers of the expected category are kept, one-year terms up to
 *   ten years. A tier's cost is the higher of `Price` and `YourPrice` plus
 *   any additional (ICANN) cost; `RegularPrice` only when neither is set
 *   (NC-6). Amounts are decimal text, rounded up to the cent (DR-5).
 */
final class NamecheapPriceSheet
{
    public const int MAX_DEPTH = 8;
    public const int MAX_PRODUCTS = 5000;

    /**
     * @param list<string> $errors error numbers when the API answered Status="ERROR"
     * @param array<string, list<NamecheapTier>> $products lower-cased product name => tiers
     */
    private function __construct(public readonly array $errors, public readonly array $products)
    {
    }

    /** @throws MalformedProviderResponse */
    public static function parse(string $xml, string $category): self
    {
        if ($xml === '' || preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new MalformedProviderResponse('Namecheap response is empty or declares a DOCTYPE or entity.');
        }
        $category = strtolower($category);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader();
        try {
            if (!$reader->XML($xml, null, LIBXML_NONET)) {
                throw new MalformedProviderResponse('Namecheap response could not be opened.');
            }
            $status = null;
            $errors = [];
            $products = [];
            $count = 0;
            $currentCategory = null;
            $currentProduct = null;
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::DOC_TYPE || $reader->depth > self::MAX_DEPTH) {
                    throw new MalformedProviderResponse('Namecheap response has a DOCTYPE or is nested too deeply.');
                }
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                switch ($reader->localName) {
                    case 'ApiResponse':
                        $status = $reader->getAttribute('Status');
                        break;
                    case 'Error':
                        $number = $reader->getAttribute('Number');
                        $errors[] = is_string($number) && preg_match('/^[0-9]{1,10}$/', $number) === 1 ? $number : 'unknown';
                        break;
                    case 'ProductCategory':
                        $currentCategory = strtolower((string) $reader->getAttribute('Name'));
                        $currentProduct = null;
                        break;
                    case 'Product':
                        if (++$count > self::MAX_PRODUCTS) {
                            throw new MalformedProviderResponse('Namecheap response lists too many products.');
                        }
                        // Names are matched across the four sheets, so case must not matter ("NET" = "net").
                        $currentProduct = $currentCategory === $category ? strtolower((string) $reader->getAttribute('Name')) : null;
                        if ($currentProduct !== null) {
                            $products[$currentProduct] ??= [];
                        }
                        break;
                    case 'Price':
                        if ($currentProduct !== null) {
                            $tier = NamecheapTier::read($reader);
                            if ($tier !== null) {
                                $products[$currentProduct][] = $tier;
                            }
                        }
                        break;
                }
            }
            if (libxml_get_errors() !== []) {
                throw new MalformedProviderResponse('Namecheap response is not well-formed XML.');
            }
            if ($status === 'ERROR') {
                return new self($errors === [] ? ['unknown'] : $errors, []);
            }
            if ($status !== 'OK') {
                throw new MalformedProviderResponse('Namecheap response has no OK status.');
            }

            return new self([], $products);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Whole cost of one tier: the higher of Price and YourPrice, plus the additional cost (NC-6). */
    public static function tierCost(CostCurrency $currency, ?string $price, ?string $yourPrice, ?string $regularPrice, ?string $additional): CostAmount|string
    {
        $best = null;
        foreach ([$price, $yourPrice] as $candidate) {
            $amount = self::amount($currency, $candidate);
            if ($amount !== null && ($best === null || $amount->minor > $best->minor)) {
                $best = $amount;
            }
        }
        $best ??= self::amount($currency, $regularPrice);
        if ($best === null) {
            return self::problem([$price, $yourPrice, $regularPrice]);
        }
        $extra = self::amount($currency, $additional);

        return $extra === null ? $best : CostAmount::ofMinor($currency, $best->minor + $extra->minor);
    }

    private static function amount(CostCurrency $currency, ?string $text): ?CostAmount
    {
        if ($text === null || $text === '') {
            return null;
        }
        try {
            return CostAmount::fromDecimalText($currency, $text);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A short, fixed word for why no price could be read (DR-14 detail).
     *
     * @param list<?string> $values
     */
    private static function problem(array $values): string
    {
        $present = array_values(array_filter($values, static fn (?string $v): bool => $v !== null && $v !== ''));
        if ($present === []) {
            return 'missing';
        }
        foreach ($present as $value) {
            if (preg_match('/^-/', $value) === 1) {
                return 'negative';
            }
        }
        foreach ($present as $value) {
            if (preg_match('/^0+(\.0+)?$/', $value) !== 1) {
                return 'format';
            }
        }

        return 'zero';
    }
}
