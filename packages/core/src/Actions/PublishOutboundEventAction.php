<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Events\OutboundEventPublished;
use Capell\Core\Exceptions\UnknownOutboundEventException;
use Capell\Core\Support\OutboundEventRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\LaravelData\Data;

/** @experimental */
final class PublishOutboundEventAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly OutboundEventRegistry $events,
    ) {}

    /**
     * Callers that retry a delivery pass the same $eventId and $occurredAt so
     * downstream consumers deduplicate on one identity; omitted, a fresh UUID
     * and the current time are used.
     */
    public function handle(
        string $eventName,
        Data $payload,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ): OutboundEventPublished {
        $definition = $this->events->definition($eventName);

        if (! is_a($payload, $definition->payloadClass)) {
            throw UnknownOutboundEventException::forPayloadMismatch($eventName, $definition->payloadClass, $payload::class);
        }

        $published = new OutboundEventPublished(
            definition: $definition,
            payload: $payload,
            eventId: $eventId ?? (string) Str::uuid(),
            occurredAt: $occurredAt ?? CarbonImmutable::now(),
        );

        event($published);

        return $published;
    }
}
