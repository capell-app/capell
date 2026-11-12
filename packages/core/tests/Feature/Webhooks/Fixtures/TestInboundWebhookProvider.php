<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Webhooks\Fixtures;

use Capell\Core\Contracts\Webhooks\InboundWebhookProvider;
use Capell\Core\Data\Webhooks\InboundWebhookData;
use Capell\Core\Exceptions\InboundWebhookRejectedException;
use Capell\Core\Support\Webhooks\HmacTimestampSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Override;

final class TestInboundWebhookProvider implements InboundWebhookProvider
{
    public const string SECRET = 'test-webhook-secret';

    #[Override]
    public function key(): string
    {
        return 'tests.provider';
    }

    #[Override]
    public function verify(Request $request): InboundWebhookData
    {
        (new HmacTimestampSignature)->verify($request->header('X-Test-Signature'), $request->getContent(), [self::SECRET]);

        $payload = json_decode($request->getContent(), true);
        throw_unless(is_array($payload) && is_string($payload['id'] ?? null) && is_string($payload['type'] ?? null) && is_int($payload['created'] ?? null), InboundWebhookRejectedException::because(InboundWebhookRejectedException::REASON_MALFORMED, 'the body is not an event'));

        return new InboundWebhookData(
            eventId: $payload['id'],
            type: $payload['type'],
            occurredAt: CarbonImmutable::createFromTimestamp($payload['created']),
            payload: $payload,
            subject: is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
        );
    }

    #[Override]
    public function handlerFor(string $type): ?string
    {
        return match ($type) {
            'order.paid' => RecordingInboundWebhookHandler::class,
            'order.broken' => FailingInboundWebhookHandler::class,
            default => null,
        };
    }
}
