<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Fixtures;

use Capell\Admin\Support\AdminEventHandlerInterface;
use Livewire\Component;

class RouterDummyHandler implements AdminEventHandlerInterface
{
    public bool $handled = false;

    /**
     * @var array<array-key, mixed>
     */
    public array $payload = [];

    public string $componentClass = '';

    public function handle(array $payload, Component $component): void
    {
        $this->handled = true;
        $this->payload = $payload;
        $this->componentClass = $component::class;
    }
}
