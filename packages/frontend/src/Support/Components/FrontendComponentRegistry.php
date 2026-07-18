<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Components;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Capell\Frontend\Contracts\FrontendComponentRegistryInterface;
use Capell\Frontend\Data\FrontendComponentData;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<FrontendComponentData> */
class FrontendComponentRegistry extends AbstractKeyedRegistry implements FrontendComponentRegistryInterface
{
    /** @var array<string, string> */
    private array $references = [];

    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $props
     */
    public function register(string $key, string $component, array $aliases = [], array $props = []): static
    {
        $data = new FrontendComponentData(
            key: $key,
            component: $component,
            aliases: array_values(array_unique($aliases)),
            props: array_values(array_unique($props)),
        );

        $this->setItem($key, $data);

        foreach ($data->references() as $reference) {
            $this->references[$reference] = $key;
        }

        return $this;
    }

    public function resolve(string $component, ?string $default = null): string
    {
        if (isset($this->references[$component])) {
            return $this->get($this->references[$component])->component;
        }

        if ($default !== null) {
            return $this->resolve($default);
        }

        return $component;
    }

    public function get(string $key): FrontendComponentData
    {
        $component = $this->getItem($key);

        throw_unless($component instanceof FrontendComponentData, InvalidArgumentException::class, sprintf('Frontend component [%s] is not registered.', $key));

        return $component;
    }

    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    public function hasReference(string $component): bool
    {
        return isset($this->references[$component]);
    }

    /** @return Collection<string, FrontendComponentData> */
    public function all(): Collection
    {
        return collect($this->allItems());
    }
}
