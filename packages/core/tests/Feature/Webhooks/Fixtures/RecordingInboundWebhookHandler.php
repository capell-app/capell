<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Webhooks\Fixtures;

use Capell\Core\Contracts\Webhooks\InboundWebhookHandler;
use Capell\Core\Models\InboundWebhookEvent;
use Override;

final class RecordingInboundWebhookHandler implements InboundWebhookHandler
{
    /** @var list<array{event: string, stale: bool}> */
    public static array $handled = [];

    #[Override]
    public function handle(InboundWebhookEvent $event): void
    {
        self::$handled[] = ['event' => $event->event_id, 'stale' => $event->isOlderThanStored()];
    }
}
