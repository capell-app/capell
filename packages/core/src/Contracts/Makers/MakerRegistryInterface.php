<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Makers;

use Illuminate\Support\Collection;

interface MakerRegistryInterface
{
    public function register(Maker $maker): void;

    public function has(string $key): bool;

    public function get(string $key): Maker;

    /**
     * @return Collection<int, Maker>
     */
    public function all(): Collection;
}
