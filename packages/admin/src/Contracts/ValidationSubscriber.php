<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts;

use Capell\Core\Contracts\EventSubscriber;

interface ValidationSubscriber extends EventSubscriber
{
    /**
     * Validate the event.
     *
     * @param  string  $event  The event name
     * @param  object  $context  The context object
     * @return bool Returns false if validation fails, true otherwise
     */
    public function validate(string $event, object $context): bool;
}
