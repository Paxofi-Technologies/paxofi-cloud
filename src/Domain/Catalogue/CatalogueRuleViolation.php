<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Catalogue;

/** A catalogue rule refused a change or a selection; `code` is a fixed, safe-to-show reason. */
final class CatalogueRuleViolation extends \DomainException
{
    public function __construct(public readonly string $reason, public readonly string $field = '')
    {
        parent::__construct($field === '' ? $reason : $field . ': ' . $reason);
    }
}
