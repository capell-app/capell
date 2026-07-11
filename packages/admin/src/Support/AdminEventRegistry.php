<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

final class AdminEventRegistry
{
    /**
     * @var array<class-string, array<string, class-string<AdminEventHandlerInterface>>>
     */
    private array $listenersByClass = [];

    /**
     * @param  class-string  $className
     * @param  class-string<AdminEventHandlerInterface>  $handlerClass
     */
    public function register(string $className, string $event, string $handlerClass): void
    {
        if (! isset($this->listenersByClass[$className])) {
            $this->listenersByClass[$className] = [];
        }

        $this->listenersByClass[$className][$event] = $handlerClass;
    }

    /**
     * @param  class-string  $className
     * @param  array<string, class-string<AdminEventHandlerInterface>>  $events
     */
    public function registerManyForClass(string $className, array $events): void
    {
        foreach ($events as $event => $handlerClass) {
            $this->register($className, $event, $handlerClass);
        }
    }

    /** @param class-string $className */
    public function has(string $className, string $event): bool
    {
        return isset($this->listenersByClass[$className][$event]);
    }

    /** @param class-string $className */
    public function unregister(string $className, string $event): void
    {
        unset($this->listenersByClass[$className][$event]);
        if (isset($this->listenersByClass[$className]) && $this->listenersByClass[$className] === []) {
            unset($this->listenersByClass[$className]);
        }
    }

    /**
     * @param  class-string  $className
     * @return array<string, class-string<AdminEventHandlerInterface>>
     */
    public function allForClass(string $className): array
    {
        return $this->listenersByClass[$className] ?? [];
    }

    /**
     * @return list<class-string>
     */
    public function classes(): array
    {
        return array_keys($this->listenersByClass);
    }
}
