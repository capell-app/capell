<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Webhooks;

use Capell\Core\Models\InboundWebhookEvent;

/**
 * Acts on one stored webhook event, on the queue. It runs at most once per event
 * unless an operator replays it, and may be retried after a failure, so its
 * effects must be safe to repeat. Call `$event->isOlderThanStored()` to skip an
 * update that arrived after a newer one about the same subject.
 */
interface InboundWebhookHandler
{
    public function handle(InboundWebhookEvent $event): void;
}
