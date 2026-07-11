<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Livewire\Component;
use Throwable;

final class AdminEventRouter
{
    public function __construct(
        private readonly Container $container,
        private readonly AdminEventRegistry $registry,
    ) {}

    /** @param array<int, mixed> $payload */
    public function handle(string $event, array $payload, Component $component): void
    {
        $handlerClass = $this->registry->allForClass($component::class)[$event] ?? null;

        if ($handlerClass === null) {
            return;
        }

        $handler = $this->container->make($handlerClass);

        throw_unless($handler instanceof AdminEventHandlerInterface, InvalidArgumentException::class, 'Admin event handler must implement ' . AdminEventHandlerInterface::class);

        try {
            $handler->handle($payload, $component);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
