<?php

declare(strict_types=1);

namespace Capell\Core\Support\Registries;

/**
 * @template T
 * @template TKey of string = string
 */
abstract class AbstractKeyedRegistry
{
    /** @var array<TKey, T> */
    private array $items = [];

    /**
     * @param  TKey  $key
     * @param  T  $item
     */
    final protected function setItem(string $key, mixed $item): void
    {
        $this->items[$key] = $item;
    }

    /**
     * @param  TKey  $key
     * @return T|null
     */
    final protected function getItem(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    /** @param TKey $key */
    final protected function hasItem(string $key): bool
    {
        return isset($this->items[$key]);
    }

    /** @return array<TKey, T> */
    final protected function allItems(): array
    {
        return $this->items;
    }

    final protected function clearItems(): void
    {
        $this->items = [];
    }
}
