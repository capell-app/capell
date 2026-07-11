<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Support\Subscriber\Contracts\Subscriber;

interface EventSubscriber extends Subscriber
{
    public function handle(string $event, object $context): void;
}
