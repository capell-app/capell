<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Support\AdminEventRegistry;
use Capell\Admin\Support\AdminEventRouter;
use Livewire\Component;

trait HasDynamicEventListeners
{
    public function routeAdminEvent(string $event, mixed ...$payload): void
    {
        if (! $this instanceof Component) {
            return;
        }

        $router = resolve(AdminEventRouter::class);

        $router->handle($event, array_values($payload), $this);
    }

    /**
     * @return array<int|string, string>
     */
    protected function getListeners(): array
    {
        $className = static::class;

        $registry = resolve(AdminEventRegistry::class);
        $events = $registry->allForClass($className);
        $listeners = $this->listeners;

        foreach (array_keys($events) as $event) {
            $listeners[$event] = 'routeAdminEvent';
        }

        return $listeners;
    }
}
