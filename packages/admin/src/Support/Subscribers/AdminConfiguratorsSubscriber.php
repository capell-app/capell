<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Subscribers;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Contracts\EventSubscriber;
use Capell\Core\Enums\ListenerEnum;

class AdminConfiguratorsSubscriber implements EventSubscriber
{
    public function handle(string $event, object $context): void
    {
        if ($event === ListenerEnum::PackageUninstalled->value) {
            CapellAdmin::clearCachedConfigurators();
        }
    }
}
