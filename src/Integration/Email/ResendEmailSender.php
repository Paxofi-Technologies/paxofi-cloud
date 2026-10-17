<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Email;

use PaxofiCloud\Application\Contracts\TransactionalEmailSender;
use PaxofiCloud\Application\Email\DeliveryFailureKind;
use PaxofiCloud\Application\Email\EmailDeliveryFailed;
use PaxofiCloud\Application\Email\EmailMessage;
use PaxofiCloud\Application\Email\EmailReceipt;
use PaxofiCloud\Integration\Provider\Http\MalformedProviderResponse;
use PaxofiCloud\Integration\Provider\Http\ProviderEndpoint;
use PaxofiCloud\Integration\Provider\Http\ProviderRequest;
use PaxofiCloud\Integration\Provider\Http\ProviderTransport;
use PaxofiCloud\Integration\Provider\Http\TransportFailure;
use PaxofiCloud\Integration\Provider\ProviderCredential;

/**
 * Resend (secondary, CEO decision 2026-10-14). POST /emails with a sending-only
 * API key and the outbox ID as Idempotency-Key, so a retry of the same outbox
 * row can never deliver twice through Resend (threat model E-5).
 */
final readonly class ResendEmailSender implements TransactionalEmailSender
{
    public const string BASE_URL = 'https://api.resend.com';

    public function __construct(
        private ProviderTransport $transport,
        private ProviderCredential $apiKey,
        private Sender $from,
    ) {
    }

    public static function endpoint(): ProviderEndpoint
    {
        return new ProviderEndpoint(self::BASE_URL);
    }

    public function name(): string
    {
        return 'resend';
    }

    public function send(EmailMessage $message): EmailReceipt
    {
        $request = ProviderRequest::post('/emails', [
            'from' => $this->from->header(),
            'to' => [$message->to->value],
            'subject' => $message->subject,
            'text' => $message->textBody,
            'html' => $message->htmlBody,
            'tags' => [['name' => 'template', 'value' => $message->tag]],
        ], ['Idempotency-Key' => $message->idempotencyKey]);

        try {
            $response = $this->transport->send(self::BASE_URL, $request, $this->apiKey);
        } catch (TransportFailure $e) {
            throw new EmailDeliveryFailed(DeliveryFailureKind::Transient, $this->name(), 'Resend unreachable: ' . $e->getMessage(), $e);
        }

        if ($response->isSuccess()) {
            try {
                $id = $response->json()['id'] ?? null;
            } catch (MalformedProviderResponse) {
                $id = null;
            }
            if (is_string($id) && $id !== '') {
                return new EmailReceipt($this->name(), $id);
            }

            throw new EmailDeliveryFailed(DeliveryFailureKind::Transient, $this->name(), 'Resend returned success without a message ID.');
        }

        throw new EmailDeliveryFailed(
            HttpStatusClassifier::kind($response->status),
            $this->name(),
            sprintf('Resend refused the message (HTTP %d).', $response->status),
        );
    }
}
