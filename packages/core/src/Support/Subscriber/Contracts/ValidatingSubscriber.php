<?php

declare(strict_types=1);

namespace Capell\Core\Support\Subscriber\Contracts;

interface ValidatingSubscriber extends Subscriber
{
    public function validate(string $event, object $context): bool;
}
