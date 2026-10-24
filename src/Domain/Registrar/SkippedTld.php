<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Registrar;

/**
 * One price-list item a sync could not use (DR-7, DR-14): the extension name
 * as the registrar sent it, a fixed reason, and a short detail such as the
 * currency code. Name and detail are untrusted, so anything outside a strict
 * pattern is dropped rather than stored.
 */
final readonly class SkippedTld
{
    public ?string $name;
    public ?string $detail;

    public function __construct(?string $name, public string $reason, ?string $detail = null)
    {
        if (preg_match('/^[a-z][a-z_]{0,31}$/', $reason) !== 1) {
            throw new \InvalidArgumentException('A skip reason is a short code.');
        }
        $name = $name === null ? null : strtolower(ltrim($name, '.'));
        $this->name = $name !== null && preg_match('/^[a-z0-9][a-z0-9.-]{0,126}$/', $name) === 1 ? $name : null;
        $this->detail = $detail !== null && preg_match('/^[A-Za-z0-9_:]{1,16}$/', $detail) === 1 ? $detail : null;
    }
}
