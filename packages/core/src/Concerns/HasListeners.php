<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Support\Subscriber\SubscriberManager;

trait HasListeners
{
    /**
     * @return SubscriberManager<object>
     */
    public function subscriberManager(): SubscriberManager
    {
        return resolve(SubscriberManager::class);
    }
}
