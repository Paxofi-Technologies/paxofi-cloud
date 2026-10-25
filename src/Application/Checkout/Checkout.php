<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Checkout;

use Paxofi\Core\Contracts\RedisClient;
use PaxofiCloud\Application\Audit\AuditEvent;
use PaxofiCloud\Application\Audit\AuditEventType;
use PaxofiCloud\Application\Audit\AuditTrail;
use PaxofiCloud\Application\Audit\ClientContext;
use PaxofiCloud\Application\Billing\BillingAccount;
use PaxofiCloud\Application\Cart\DomainCart;
use PaxofiCloud\Application\Contracts\BillingStore;
use PaxofiCloud\Application\Contracts\Clock;
use PaxofiCloud\Application\Contracts\DuplicateIdempotencyKey;
use PaxofiCloud\Application\Contracts\OrderStore;
use PaxofiCloud\Application\Contracts\RateLimiter;
use PaxofiCloud\Application\Contracts\TaxRateStore;
use PaxofiCloud\Application\Domains\DomainSearchRefused;
use PaxofiCloud\Application\Domains\RegistrarCheck;
use PaxofiCloud\Application\Extensions\ExtensionQuotes;
use PaxofiCloud\Domain\Billing\TaxRate;
use PaxofiCloud\Domain\Money\Currency;
use PaxofiCloud\Domain\Money\Money;

/**
 * Checkout for domain registrations (THREAT-MODEL-COMMERCE.md CD-23, CD-24;
 * SRS CRT-003, CRT-005, CMP-010, BR-10). A review is built from the server
 * cart and the account's billing details only, checked with the registrar by
 * the provider worker, and kept for 30 minutes bound to the account. Placing
 * re-quotes every line and writes the order and invoice in one transaction.
 * Premium names are not sold at MVP (CRT-003), so they block the order.
 *
 * @phpstan-type ReviewLine array{price: int, renew: int, tax: int, registrar: string, cost_minor: int, cost_currency: string, fx: string}
 * @phpstan-type Review array{account: string, expires: int, currency: string, tax: array{country: string, name: string, bp: int}, billing_country: string, visitor_country: ?string, confirm_country: bool, terms: string, jobs: list<string>, checked: array<string, array{status: string, premium: bool}>|null, lines: list<array{domain: string, line: ReviewLine|null}>}
 */
final readonly class Checkout
{
    public const int REVIEW_TTL_SECONDS = 1800;
    public const int REVIEWS_PER_HOUR = 10;
    public const int ORDERS_PER_HOUR = 10;
    public const int EXPIRES_AFTER_SECONDS = 259_200;
    private const string KEY = '/^[A-Za-z0-9_-]{16,64}$/';

    public function __construct(
        private DomainCart $cart,
        private ExtensionQuotes $quotes,
        private RegistrarCheck $registrar,
        private BillingStore $billing,
        private TaxRateStore $taxes,
        private OrderStore $orders,
        private RedisClient $redis,
        private RateLimiter $limiter,
        private AuditTrail $audit,
        private Clock $clock,
        private string $termsVersion,
    ) {
    }

    public static function reviewKey(string $id): string
    {
        return 'pc:checkout:' . hash('sha256', $id);
    }

    /** @throws CheckoutRefused */
    public function startReview(string $accountId, ?string $visitorCountry): string
    {
        $this->limit('checkout:review:', $accountId, self::REVIEWS_PER_HOUR);
        $billing = $this->billing->find($accountId) ?? throw new CheckoutRefused(CheckoutRefused::BILLING_REQUIRED);
        $domains = $this->cart->domains($accountId);
        if ($domains === []) {
            throw new CheckoutRefused(CheckoutRefused::CART_EMPTY);
        }
        $now = $this->clock->now();
        $tax = $this->taxes->inForce($billing->details->country, $now);
        $lines = [];
        $sellable = [];
        foreach ($domains as $domain) {
            $line = $this->priceLine($domain, $billing->currency, $tax);
            if ($line !== null) {
                $sellable[] = $domain;
            }
            $lines[] = ['domain' => $domain, 'line' => $line];
        }
        try {
            $jobs = $sellable === [] ? [] : $this->registrar->queueForCheckout($sellable);
        } catch (DomainSearchRefused $e) {
            throw new CheckoutRefused(CheckoutRefused::BUSY, $e->retryAfter);
        }
        $visitor = $visitorCountry !== null && preg_match('/^[A-Z]{2}$/', $visitorCountry) === 1 ? $visitorCountry : null;
        $id = bin2hex(random_bytes(16));
        $this->save($id, [
            'account' => $accountId,
            'expires' => $now->getTimestamp() + self::REVIEW_TTL_SECONDS,
            'currency' => $billing->currency->value,
            'tax' => ['country' => $tax->country, 'name' => $tax->name, 'bp' => $tax->rate->value],
            'billing_country' => $billing->details->country,
            'visitor_country' => $visitor,
            'confirm_country' => $this->needsCountryConfirmation($billing, $tax, $visitor, $now),
            'terms' => $this->termsVersion,
            'jobs' => $jobs,
            'checked' => null,
            'lines' => $lines,
        ], self::REVIEW_TTL_SECONDS);

        return $id;
    }

    /**
     * The review as the customer sees it; costs, registrars and rate sets stay on the server (CD-5).
     *
     * @return array{id: string, status: string, expires_at: string, currency: string, lines: list<array<string, mixed>>, subtotal: int, tax: array{name: string, rate_bp: int, amount: int}, total: int, blocked: bool, confirm_billing_country: bool, billing_country: string, terms_version: string, auto_renew: bool}
     * @throws CheckoutRefused
     */
    public function review(string $accountId, string $id): array
    {
        [$review, $ttl] = $this->load($accountId, $id);
        $review = $this->collect($id, $review, $ttl);

        return $this->present($id, $review);
    }

    /**
     * @return array{order_id: string, order_number: string, invoice_number: string, replayed: bool}
     * @throws CheckoutRefused
     */
    public function place(string $identityId, string $accountId, string $reviewId, bool $acceptTerms, bool $confirmCountry, string $idempotencyKey, ClientContext $client): array
    {
        if (preg_match(self::KEY, $idempotencyKey) !== 1) {
            throw new CheckoutRefused(CheckoutRefused::KEY_INVALID);
        }
        if (!$this->billing->isOwner($identityId, $accountId)) {
            throw new CheckoutRefused(CheckoutRefused::NOT_OWNER);
        }
        $requestHash = hash('sha256', 'checkout|' . $reviewId);
        $previous = $this->orders->findByIdempotencyKey($accountId, $idempotencyKey);
        if ($previous !== null) {
            return $this->replay($accountId, $previous, $requestHash);
        }
        $this->limit('checkout:order:', $accountId, self::ORDERS_PER_HOUR);
        [$review, $ttl] = $this->load($accountId, $reviewId);
        $review = $this->collect($reviewId, $review, $ttl);
        $view = $this->present($reviewId, $review);
        if ($view['status'] !== 'ready') {
            throw new CheckoutRefused(CheckoutRefused::REVIEW_PENDING);
        }
        if ($view['blocked'] === true) {
            throw new CheckoutRefused(CheckoutRefused::REVIEW_BLOCKED);
        }
        if (!$acceptTerms) {
            throw new CheckoutRefused(CheckoutRefused::TERMS_REQUIRED);
        }
        if ($review['confirm_country'] && !$confirmCountry) {
            throw new CheckoutRefused(CheckoutRefused::COUNTRY_CONFIRMATION);
        }

        // Re-quote now (CD-24): any difference from what the customer saw stops the order.
        $now = $this->clock->now();
        $billing = $this->billing->find($accountId) ?? throw new CheckoutRefused(CheckoutRefused::BILLING_REQUIRED);
        $tax = $this->taxes->inForce($billing->details->country, $now);
        if ($billing->currency->value !== $review['currency'] || $billing->details->country !== $review['billing_country']
            || $tax->rate->value !== $review['tax']['bp'] || $tax->country !== $review['tax']['country']) {
            throw new CheckoutRefused(CheckoutRefused::PRICE_CHANGED);
        }
        $lines = [];
        foreach ($review['lines'] as $row) {
            $fresh = $this->priceLine($row['domain'], $billing->currency, $tax);
            $seen = $row['line'];
            if ($fresh === null || $seen === null || $fresh['price'] !== $seen['price'] || $fresh['renew'] !== $seen['renew'] || $fresh['tax'] !== $seen['tax']) {
                throw new CheckoutRefused(CheckoutRefused::PRICE_CHANGED);
            }
            $lines[] = new NewOrderLine($row['domain'], $fresh['price'], $fresh['tax'], $fresh['renew'], $fresh['registrar'], $fresh['cost_minor'], $fresh['cost_currency'], $fresh['fx']);
        }

        $order = new NewOrder(
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
            $accountId,
            $identityId,
            $billing->currency,
            $tax,
            $billing->details,
            $review['visitor_country'],
            $review['confirm_country'],
            $review['terms'],
            $idempotencyKey,
            $requestHash,
            $now,
            $now->modify('+' . self::EXPIRES_AFTER_SECONDS . ' seconds'),
            $lines,
        );
        try {
            $numbers = $this->orders->place($order);
        } catch (DuplicateIdempotencyKey) {
            // A concurrent request with the same key won the race: answer with its order (C-17).
            $previous = $this->orders->findByIdempotencyKey($accountId, $idempotencyKey) ?? throw new CheckoutRefused(CheckoutRefused::KEY_REUSED);

            return $this->replay($accountId, $previous, $requestHash);
        }
        $this->cart->removeDomains($accountId, array_map(static fn (NewOrderLine $l): string => $l->domain, $lines));
        $this->redis->delete(self::reviewKey($reviewId));
        $this->audit->record(AuditEventType::ORDER_PLACED, AuditEvent::SUCCESS, $client, $identityId, $accountId, [
            'order' => $order->orderId,
            'lines' => count($lines),
            'total_minor' => $order->subtotalMinor() + $order->taxMinor(),
            'currency' => strtolower($order->currency->value),
            'flagged' => $order->flaggedForReview,
        ]);

        return ['order_id' => $order->orderId, 'order_number' => $numbers['order_number'], 'invoice_number' => $numbers['invoice_number'], 'replayed' => false];
    }

    /**
     * @param array{id: string, request_hash: string} $previous
     * @return array{order_id: string, order_number: string, invoice_number: string, replayed: bool}
     * @throws CheckoutRefused
     */
    private function replay(string $accountId, array $previous, string $requestHash): array
    {
        if (!hash_equals($previous['request_hash'], $requestHash)) {
            throw new CheckoutRefused(CheckoutRefused::KEY_REUSED);
        }
        $order = $this->orders->find($accountId, $previous['id']) ?? throw new CheckoutRefused(CheckoutRefused::KEY_REUSED);

        return ['order_id' => $order['id'], 'order_number' => $order['number'], 'invoice_number' => $order['invoice_number'], 'replayed' => true];
    }

    /** @return array{price: int, renew: int, tax: int, registrar: string, cost_minor: int, cost_currency: string, fx: string}|null */
    private function priceLine(string $domain, Currency $currency, TaxRate $tax): ?array
    {
        $tld = explode('.', $domain, 2)[1] ?? '';
        $quote = $tld === '' ? null : $this->quotes->checkoutQuote($tld);
        $price = $quote?->register($currency);
        $renew = $quote?->renew($currency);
        if ($quote === null || $price === null || $renew === null || $price <= 0 || $renew <= 0) {
            return null;
        }

        return [
            'price' => $price,
            'renew' => $renew,
            // Domain registration is taxable (BR-06, CD-22; accountant to confirm, CR-7).
            'tax' => $tax->on(new Money($price, $currency))->minorUnits,
            'registrar' => $quote->registrar,
            'cost_minor' => $quote->registerCost->minor,
            'cost_currency' => $quote->registerCost->currency->value,
            'fx' => $quote->rateSetId,
        ];
    }

    /** CD-12: the billing country has a lower rate than where the visitor is, so the customer confirms it and staff review the order. */
    private function needsCountryConfirmation(BillingAccount $billing, TaxRate $tax, ?string $visitor, \DateTimeImmutable $now): bool
    {
        if ($visitor === null || $visitor === $billing->details->country) {
            return false;
        }

        return $tax->rate->value < $this->taxes->inForce($visitor, $now)->rate->value;
    }

    /** @throws CheckoutRefused */
    private function limit(string $prefix, string $accountId, int $perHour): void
    {
        $retry = $this->limiter->hit($prefix . hash('sha256', $accountId), $perHour, 3600);
        if ($retry > 0) {
            throw new CheckoutRefused(CheckoutRefused::TOO_MANY, $retry);
        }
    }

    /**
     * @return array{0: Review, 1: int} the review and its remaining seconds
     * @throws CheckoutRefused
     * @phpstan-impure
     */
    private function load(string $accountId, string $id): array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new CheckoutRefused(CheckoutRefused::REVIEW_NOT_FOUND);
        }
        $raw = $this->redis->get(self::reviewKey($id));
        $review = is_string($raw) ? self::parse(json_decode($raw, true)) : null;
        $ttl = $review === null ? 0 : $review['expires'] - $this->clock->now()->getTimestamp();
        // Unknown, expired and another account's reviews look the same (C-41).
        if ($review === null || !hash_equals($review['account'], $accountId) || $ttl <= 0) {
            throw new CheckoutRefused(CheckoutRefused::REVIEW_NOT_FOUND);
        }

        return [$review, $ttl];
    }

    /**
     * Rebuilds a stored review field by field; anything malformed is treated as no review.
     *
     * @return Review|null
     */
    private static function parse(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        $tax = $data['tax'] ?? null;
        $visitor = $data['visitor_country'] ?? null;
        if (!is_string($data['account'] ?? null) || !is_int($data['expires'] ?? null) || !in_array($data['currency'] ?? null, ['NGN', 'USD'], true)
            || !is_array($tax) || !is_string($tax['country'] ?? null) || !is_string($tax['name'] ?? null) || !is_int($tax['bp'] ?? null)
            || !is_string($data['billing_country'] ?? null) || ($visitor !== null && !is_string($visitor)) || !is_bool($data['confirm_country'] ?? null)
            || !is_string($data['terms'] ?? null) || !is_array($data['jobs'] ?? null) || !is_array($data['lines'] ?? null)) {
            return null;
        }
        $jobs = array_values(array_filter($data['jobs'], 'is_string'));
        $checked = null;
        if (is_array($data['checked'] ?? null)) {
            $checked = [];
            foreach ($data['checked'] as $domain => $answer) {
                if (is_string($domain) && is_array($answer) && is_string($answer['status'] ?? null) && is_bool($answer['premium'] ?? null)) {
                    $checked[$domain] = ['status' => $answer['status'], 'premium' => $answer['premium']];
                }
            }
        }
        $lines = [];
        foreach ($data['lines'] as $row) {
            if (!is_array($row) || !is_string($row['domain'] ?? null)) {
                return null;
            }
            $l = $row['line'] ?? null;
            $line = null;
            if (is_array($l)) {
                if (!is_int($l['price'] ?? null) || !is_int($l['renew'] ?? null) || !is_int($l['tax'] ?? null) || !is_string($l['registrar'] ?? null)
                    || !is_int($l['cost_minor'] ?? null) || !is_string($l['cost_currency'] ?? null) || !is_string($l['fx'] ?? null)) {
                    return null;
                }
                $line = ['price' => $l['price'], 'renew' => $l['renew'], 'tax' => $l['tax'], 'registrar' => $l['registrar'], 'cost_minor' => $l['cost_minor'], 'cost_currency' => $l['cost_currency'], 'fx' => $l['fx']];
            }
            $lines[] = ['domain' => $row['domain'], 'line' => $line];
        }

        return [
            'account' => $data['account'],
            'expires' => $data['expires'],
            'currency' => $data['currency'],
            'tax' => ['country' => $tax['country'], 'name' => $tax['name'], 'bp' => $tax['bp']],
            'billing_country' => $data['billing_country'],
            'visitor_country' => $visitor,
            'confirm_country' => $data['confirm_country'],
            'terms' => $data['terms'],
            'jobs' => $jobs,
            'checked' => $checked,
            'lines' => $lines,
        ];
    }

    /**
     * Copies the registrar answers into the review once every job has answered,
     * because the answers themselves live for only two minutes (DS-13).
     *
     * @param Review $review
     * @return Review
     */
    private function collect(string $id, array $review, int $ttl): array
    {
        if ($review['checked'] !== null) {
            return $review;
        }
        $checked = [];
        foreach ($review['jobs'] as $job) {
            $result = $this->registrar->result($job);
            if ($result['status'] === 'pending') {
                return $review;
            }
            foreach ($result['results'] as $answer) {
                $checked[$answer['domain']] = ['status' => $answer['status'], 'premium' => $answer['premium']];
            }
        }
        $review['checked'] = $checked;
        $this->save($id, $review, $ttl);

        return $review;
    }

    /**
     * @param Review $review
     * @return array{id: string, status: string, expires_at: string, currency: string, lines: list<array<string, mixed>>, subtotal: int, tax: array{name: string, rate_bp: int, amount: int}, total: int, blocked: bool, confirm_billing_country: bool, billing_country: string, terms_version: string, auto_renew: bool}
     */
    private function present(string $id, array $review): array
    {
        $checked = $review['checked'];
        $currency = Currency::fromCode($review['currency']);
        $lines = [];
        $subtotal = 0;
        $taxTotal = 0;
        $blocked = false;
        foreach ($review['lines'] as $row) {
            $line = $row['line'];
            $answer = $checked[$row['domain']] ?? null;
            $status = match (true) {
                $line === null => 'not_for_sale',
                $checked === null => 'checking',
                $answer === null => 'unknown',
                $answer['premium'] === true => 'premium',
                default => in_array($answer['status'], ['available', 'taken', 'unknown'], true) ? $answer['status'] : 'taken',
            };
            if ($status !== 'available' && $status !== 'checking') {
                $blocked = true;
            }
            if ($status === 'available' && $line !== null) {
                $subtotal += $line['price'];
                $taxTotal += $line['tax'];
            }
            $lines[] = [
                'domain' => $row['domain'],
                'years' => 1,
                'status' => $status,
                'price' => $line['price'] ?? null,
                'tax' => $line['tax'] ?? null,
                'total' => $line === null ? null : $line['price'] + $line['tax'],
                'renewal_price' => $line['renew'] ?? null,
            ];
        }

        return [
            'id' => $id,
            'status' => $checked === null ? 'pending' : 'ready',
            'expires_at' => (new \DateTimeImmutable('@' . $review['expires']))->format(\DATE_ATOM),
            'currency' => $currency->value,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'tax' => ['name' => $review['tax']['name'], 'rate_bp' => $review['tax']['bp'], 'amount' => $taxTotal],
            'total' => $subtotal + $taxTotal,
            'blocked' => $blocked,
            'confirm_billing_country' => $review['confirm_country'],
            'billing_country' => $review['billing_country'],
            'terms_version' => $review['terms'],
            'auto_renew' => false,
        ];
    }

    /** @param array<string, mixed> $review */
    private function save(string $id, array $review, int $ttl): void
    {
        $this->redis->setEx(self::reviewKey($id), max(1, $ttl), json_encode($review, JSON_THROW_ON_ERROR));
    }
}
