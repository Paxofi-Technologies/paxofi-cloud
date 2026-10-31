<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Support;

use PaxofiCloud\Application\Contracts\PaymentGateway;
use PaxofiCloud\Application\Contracts\PaymentGateways;
use PaxofiCloud\Application\Payments\CheckoutRequest;
use PaxofiCloud\Application\Payments\GatewayVerification;
use PaxofiCloud\Application\Payments\HostedCheckout;
use PaxofiCloud\Application\Payments\PaymentOutcome;
use PaxofiCloud\Application\Payments\WebhookHint;
use PaxofiCloud\Domain\Payments\Gateway;
use PaxofiCloud\Domain\Payments\PaymentReference;

/**
 * Gateways whose initialise(), verify() and webhookHint() answer from a script: a result to
 * return, or a Throwable to throw. Every call is recorded so tests can check
 * what was sent.
 */
final class ScriptedPaymentGateways implements PaymentGateways
{
    /** @var list<array{gateway: Gateway, request: CheckoutRequest}> */
    public array $requests = [];
    /** @var \Closure(CheckoutRequest): (HostedCheckout|\Throwable)|null */
    public ?\Closure $answer = null;
    /** @var list<PaymentReference> references passed to verify() */
    public array $verified = [];
    /** @var \Closure(PaymentReference): (GatewayVerification|\Throwable)|null */
    public ?\Closure $verifyAnswer = null;
    /** @var \Closure(string, array<string, string>): (WebhookHint|\Throwable|null)|null */
    public ?\Closure $webhookAnswer = null;

    public function for(Gateway $gateway): PaymentGateway
    {
        return new class ($gateway, $this) implements PaymentGateway {
            public function __construct(private Gateway $gateway, private ScriptedPaymentGateways $script)
            {
            }

            public function gateway(): Gateway
            {
                return $this->gateway;
            }

            public function initialise(CheckoutRequest $request): HostedCheckout
            {
                $this->script->requests[] = ['gateway' => $this->gateway, 'request' => $request];
                $answer = $this->script->answer !== null
                    ? ($this->script->answer)($request)
                    : new HostedCheckout($request->reference, 'https://checkout.paystack.com/test-page');
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }

            public function verify(PaymentReference $reference): GatewayVerification
            {
                $this->script->verified[] = $reference;
                $answer = $this->script->verifyAnswer !== null
                    ? ($this->script->verifyAnswer)($reference)
                    : new GatewayVerification($reference, PaymentOutcome::Pending);
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }

            public function webhookHint(string $rawBody, array $headers): ?WebhookHint
            {
                $answer = $this->script->webhookAnswer !== null ? ($this->script->webhookAnswer)($rawBody, $headers) : null;
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        };
    }
}
