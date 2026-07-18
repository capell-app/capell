<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Support\Subscriber\SubscriberRegistry;

trait HasListeners
{
    /**
     * @return SubscriberRegistry<object>
     */
    public function subscriberManager(): SubscriberRegistry
    {
        return resolve(SubscriberRegistry::class);
    }
}
