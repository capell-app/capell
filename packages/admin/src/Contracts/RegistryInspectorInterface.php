<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts;

use Illuminate\Support\Collection;

interface RegistryInspectorInterface
{
    /**
     * @return Collection<int|string, mixed>
     */
    public function configurators(?string $configuratorType = null): Collection;

    /**
     * @return Collection<int|string, mixed>
     */
    public function components(?string $componentType = null): Collection;

    /**
     * @return Collection<int|string, mixed>
     */
    public function blocks(): Collection;

    /**
     * @return Collection<int|string, mixed>
     */
    public function widgets(): Collection;
}
