<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendComponentData;
use Illuminate\Support\Collection;

interface FrontendComponentRegistryInterface
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $props
     */
    public function register(string $key, string $component, array $aliases = [], array $props = []): static;

    public function resolve(string $component, ?string $default = null): string;

    public function get(string $key): FrontendComponentData;

    public function has(string $key): bool;

    public function hasReference(string $component): bool;

    /** @return Collection<string, FrontendComponentData> */
    public function all(): Collection;
}
