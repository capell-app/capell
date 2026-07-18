<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers;

use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class MakerRegistry implements MakerRegistryInterface
{
    /** @var array<string, Maker> */
    private array $makers = [];

    public function register(Maker $maker): void
    {
        $this->makers[$maker->definition()->key] = $maker;
    }

    public function has(string $key): bool
    {
        return isset($this->makers[$key]);
    }

    public function get(string $key): Maker
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException(sprintf('Maker [%s] is not registered.', $key));
        }

        return $this->makers[$key];
    }

    public function all(): Collection
    {
        return collect($this->makers)
            ->sortBy(fn (Maker $maker): string => $maker->definition()->group . ':' . $maker->definition()->label)
            ->values();
    }
}
