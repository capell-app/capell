<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Webhooks;

use Capell\Core\Enums\InboundWebhookEventStatus;
use Capell\Core\Enums\InboundWebhookReplayOutcome;
use Capell\Core\Jobs\Webhooks\ProcessInboundWebhookEventJob;
use Capell\Core\Models\InboundWebhookEvent;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Queues a stored inbound webhook event for its handler again, for example after the cause of a
 * failure was fixed. An ignored event has no handler. A processed event is replayed only when
 * forced, because its handler has already applied it once. An event whose job is already queued
 * or running is left alone, so a replay never runs the handler twice at the same time.
 *
 * @method static InboundWebhookReplayOutcome run(InboundWebhookEvent $event, bool $force = false)
 */
final class ReplayInboundWebhookEventAction
{
    use AsFake;
    use AsObject;

    public function handle(InboundWebhookEvent $event, bool $force = false): InboundWebhookReplayOutcome
    {
        if ($event->status === InboundWebhookEventStatus::Ignored || $event->handler === null) {
            return InboundWebhookReplayOutcome::Ignored;
        }

        if ($event->status === InboundWebhookEventStatus::Processed && ! $force) {
            return InboundWebhookReplayOutcome::AlreadyProcessed;
        }

        $job = new ProcessInboundWebhookEventJob((int) $event->getKey());

        // Dispatching drops the job silently while a copy holds its unique lock, so check first
        // and report what actually happened.
        $probe = Cache::lock(UniqueLock::getKey($job), 1);

        if (! $probe->get()) {
            return InboundWebhookReplayOutcome::AlreadyQueued;
        }

        $probe->release();

        $event->forceFill(['status' => InboundWebhookEventStatus::Received])->save();
        dispatch($job);

        return InboundWebhookReplayOutcome::Queued;
    }
}
