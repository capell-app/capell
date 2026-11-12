<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Enums\InboundWebhookEventStatus;
use Capell\Core\Jobs\Webhooks\ProcessInboundWebhookEventJob;
use Capell\Core\Models\InboundWebhookEvent;
use Illuminate\Console\Command;

/**
 * Queues a stored webhook event for its handler again, for example after the
 * cause of a failure was fixed. A processed event is replayed only with --force,
 * because its handler has already applied it once.
 */
final class ReplayInboundWebhookEventCommand extends Command
{
    protected $signature = 'capell:webhooks:replay {event : The stored webhook event id} {--force : Replay an event that was already processed}';

    protected $description = 'Queue a stored inbound webhook event for its handler again';

    public function handle(): int
    {
        $id = $this->argument('event');
        $event = is_string($id) && ctype_digit($id) ? InboundWebhookEvent::query()->find((int) $id) : null;

        if (! $event instanceof InboundWebhookEvent) {
            $this->error((string) __('capell-core::message.webhook_replay_missing'));

            return self::FAILURE;
        }

        if ($event->status === InboundWebhookEventStatus::Ignored || $event->handler === null) {
            $this->error((string) __('capell-core::message.webhook_replay_ignored'));

            return self::FAILURE;
        }

        if ($event->status === InboundWebhookEventStatus::Processed && $this->option('force') !== true) {
            $this->error((string) __('capell-core::message.webhook_replay_processed'));

            return self::FAILURE;
        }

        $event->forceFill(['status' => InboundWebhookEventStatus::Received])->save();
        dispatch(new ProcessInboundWebhookEventJob((int) $event->getKey()));

        $this->info((string) __('capell-core::message.webhook_replay_queued', ['id' => $event->getKey()]));

        return self::SUCCESS;
    }
}
