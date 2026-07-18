<?php

declare(strict_types=1);

namespace Capell\Core\Support\Registries;

/**
 * @template T
 */
abstract class AbstractKeyedRegistry
{
    /** @var array<string, T> */
    private array $items = [];

    /** @param T $item */
    final protected function setItem(string $key, mixed $item): void
    {
        $this->items[$key] = $item;
    }

    /** @return T|null */
    final protected function getItem(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    final protected function hasItem(string $key): bool
    {
        return isset($this->items[$key]);
    }

    /** @return array<string, T> */
    final protected function allItems(): array
    {
        return $this->items;
    }

    final protected function clearItems(): void
    {
        $this->items = [];
    }
}
