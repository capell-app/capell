<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers;

use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<Maker> */
final class MakerRegistry extends AbstractKeyedRegistry implements MakerRegistryInterface
{
    public function register(Maker $maker): void
    {
        $this->setItem($maker->definition()->key, $maker);
    }

    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    public function get(string $key): Maker
    {
        return $this->getItem($key)
            ?? throw new InvalidArgumentException(sprintf('Maker [%s] is not registered.', $key));
    }

    public function all(): Collection
    {
        return collect($this->allItems())
            ->sortBy(fn (Maker $maker): string => $maker->definition()->group . ':' . $maker->definition()->label)
            ->values();
    }

    public function clear(): void
    {
        $this->clearItems();
    }
}
