<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Livewire\Component;

interface AdminEventHandlerInterface
{
    /** @param array<int, mixed> $payload */
    public function handle(array $payload, Component $component): void;
}
