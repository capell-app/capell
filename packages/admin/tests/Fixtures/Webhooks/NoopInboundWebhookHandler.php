<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Webhooks;

use Capell\Core\Contracts\Webhooks\InboundWebhookHandler;
use Capell\Core\Models\InboundWebhookEvent;

final class NoopInboundWebhookHandler implements InboundWebhookHandler
{
    public function handle(InboundWebhookEvent $event): void {}
}
