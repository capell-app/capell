<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Fixtures;

use Capell\Admin\Support\AdminEventHandlerInterface;
use Livewire\Component;

class DummyHandler implements AdminEventHandlerInterface
{
    /**
     * @var array{0?: array<array-key, mixed>, 1?: class-string<Component>}
     */
    public array $called = [];

    public function handle(array $payload, Component $component): void
    {
        $this->called = [$payload, $component::class];
    }
}
