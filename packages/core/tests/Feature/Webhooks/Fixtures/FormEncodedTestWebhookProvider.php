<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Webhooks\Fixtures;

use Capell\Core\Contracts\Webhooks\AcceptsFormEncodedWebhooks;
use Capell\Core\Contracts\Webhooks\AnswersWebhookValidationRequests;
use Capell\Core\Data\Webhooks\InboundWebhookData;
use Capell\Core\Exceptions\InboundWebhookRejectedException;
use Capell\Core\Support\Webhooks\HmacTimestampSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Override;

final class FormEncodedTestWebhookProvider implements AcceptsFormEncodedWebhooks, AnswersWebhookValidationRequests
{
    #[Override]
    public function key(): string
    {
        return 'tests.form-provider';
    }

    #[Override]
    public function verify(Request $request): InboundWebhookData
    {
        (new HmacTimestampSignature)->verify($request->header('X-Test-Signature'), $request->getContent(), [TestInboundWebhookProvider::SECRET]);

        parse_str($request->getContent(), $fields);
        throw_unless(is_string($fields['id'] ?? null) && is_string($fields['type'] ?? null), InboundWebhookRejectedException::because(InboundWebhookRejectedException::REASON_MALFORMED, 'the body is not an event'));

        /** @var array<string, mixed> $fields */
        return new InboundWebhookData(
            eventId: $fields['id'],
            type: $fields['type'],
            occurredAt: CarbonImmutable::now(),
            payload: $fields,
        );
    }

    #[Override]
    public function handlerFor(string $type): ?string
    {
        return $type === 'order.paid' ? RecordingInboundWebhookHandler::class : null;
    }
}
