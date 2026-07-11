<?php

declare(strict_types=1);

namespace Capell\Core\Support\Subscriber\Contracts;

interface Subscriber
{
    public function handle(string $event, object $context): void;
}
