<?php

declare(strict_types=1);

namespace Capell\Core\Jobs\Webhooks;

use Capell\Core\Contracts\Webhooks\InboundWebhookHandler;
use Capell\Core\Enums\InboundWebhookEventStatus;
use Capell\Core\Models\InboundWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Runs the stored handler for one webhook event. The queue's unique lock keeps
 * two copies of the same event from running at once; a processed or ignored
 * event is never run again by this job, so a duplicate dispatch is a no-op.
 *
 * A failure is recorded with the exception class only (messages can carry
 * payload data) and rethrown so the queue retries with backoff.
 */
final class ProcessInboundWebhookEventJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $eventId) {}

    public function uniqueId(): string
    {
        return 'capell-inbound-webhook-event-' . $this->eventId;
    }

    public function handle(): void
    {
        $event = DB::transaction(function (): ?InboundWebhookEvent {
            $event = InboundWebhookEvent::query()->lockForUpdate()->find($this->eventId);

            if (! $event instanceof InboundWebhookEvent
                || in_array($event->status, [InboundWebhookEventStatus::Processed, InboundWebhookEventStatus::Ignored], true)) {
                return null;
            }

            $event->forceFill([
                'status' => InboundWebhookEventStatus::Processing,
                'attempts' => $event->attempts + 1,
            ])->save();

            return $event;
        });

        if ($event === null) {
            return;
        }

        try {
            $this->handler($event)->handle($event);
        } catch (Throwable $throwable) {
            $event->forceFill([
                'status' => InboundWebhookEventStatus::Failed,
                'last_error' => $throwable::class,
            ])->save();

            throw $throwable;
        }

        $event->forceFill([
            'status' => InboundWebhookEventStatus::Processed,
            'last_error' => null,
            'processed_at' => CarbonImmutable::now(),
        ])->save();
    }

    private function handler(InboundWebhookEvent $event): InboundWebhookHandler
    {
        $class = $event->handler;
        throw_if($class === null || ! is_subclass_of($class, InboundWebhookHandler::class), LogicException::class, 'The stored webhook handler is not an inbound webhook handler.');

        $handler = resolve($class);
        throw_unless($handler instanceof InboundWebhookHandler, LogicException::class, 'The stored webhook handler is not an inbound webhook handler.');

        return $handler;
    }
}
