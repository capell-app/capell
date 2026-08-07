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

final class PublishOutboundEventAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly OutboundEventRegistry $events,
    ) {}

    public function handle(string $eventName, Data $payload): OutboundEventPublished
    {
        $definition = $this->events->definition($eventName);

        if (! is_a($payload, $definition->payloadClass)) {
            throw UnknownOutboundEventException::forPayloadMismatch($eventName, $definition->payloadClass, $payload::class);
        }

        $published = new OutboundEventPublished(
            definition: $definition,
            payload: $payload,
            eventId: (string) Str::uuid(),
            occurredAt: CarbonImmutable::now(),
        );

        event($published);

        return $published;
    }
}
