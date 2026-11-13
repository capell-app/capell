<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Webhooks\Fixtures;

use Capell\Core\Contracts\Webhooks\InboundWebhookHandler;
use Capell\Core\Models\InboundWebhookEvent;
use Override;
use RuntimeException;

final class FailingInboundWebhookHandler implements InboundWebhookHandler
{
    #[Override]
    public function handle(InboundWebhookEvent $event): void
    {
        throw new RuntimeException('card ending 4242 for jane@example.test declined');
    }
}
