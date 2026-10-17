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
 * Postmark (primary, CEO decision 2026-10-14). POST /email on the
 * transactional "outbound" stream with the Server token header. Postmark has
 * no idempotency header, so the outbox ID travels as metadata and duplicates
 * are prevented by the worker's claim lock (threat model E-5).
 */
final readonly class PostmarkEmailSender implements TransactionalEmailSender
{
    public const string BASE_URL = 'https://api.postmarkapp.com';

    public function __construct(
        private ProviderTransport $transport,
        private ProviderCredential $serverToken,
        private Sender $from,
        private string $messageStream = 'outbound',
    ) {
    }

    public static function endpoint(): ProviderEndpoint
    {
        return new ProviderEndpoint(self::BASE_URL, 'X-Postmark-Server-Token', '');
    }

    public function name(): string
    {
        return 'postmark';
    }

    public function send(EmailMessage $message): EmailReceipt
    {
        $request = ProviderRequest::post('/email', [
            'From' => $this->from->header(),
            'To' => $message->to->value,
            'Subject' => $message->subject,
            'TextBody' => $message->textBody,
            'HtmlBody' => $message->htmlBody,
            'Tag' => $message->tag,
            'MessageStream' => $this->messageStream,
            'TrackOpens' => false,
            'TrackLinks' => 'None',
            'Metadata' => ['outbox_id' => $message->idempotencyKey],
        ]);

        try {
            $response = $this->transport->send(self::BASE_URL, $request, $this->serverToken);
        } catch (TransportFailure $e) {
            throw new EmailDeliveryFailed(DeliveryFailureKind::Transient, $this->name(), 'Postmark unreachable: ' . $e->getMessage(), $e);
        }

        try {
            $body = $response->json();
        } catch (MalformedProviderResponse $e) {
            $body = [];
        }
        $errorCode = is_int($body['ErrorCode'] ?? null) ? $body['ErrorCode'] : null;

        if ($response->isSuccess() && $errorCode === 0 && is_string($body['MessageID'] ?? null) && $body['MessageID'] !== '') {
            return new EmailReceipt($this->name(), $body['MessageID']);
        }
        if ($response->isSuccess()) {
            throw new EmailDeliveryFailed(DeliveryFailureKind::Transient, $this->name(), 'Postmark returned success without a message ID.');
        }

        throw new EmailDeliveryFailed(
            HttpStatusClassifier::kind($response->status),
            $this->name(),
            sprintf('Postmark refused the message (HTTP %d, ErrorCode %s).', $response->status, $errorCode ?? 'n/a'),
        );
    }
}
