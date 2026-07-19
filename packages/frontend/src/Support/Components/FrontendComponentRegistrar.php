<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Components;

use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Enums\LivewirePageComponentEnum;
use Capell\Frontend\Contracts\FrontendComponentContributor;
use Capell\Frontend\Contracts\FrontendComponentRegistryInterface;
use Capell\Frontend\Data\FrontendComponentContributionData;
use Capell\Frontend\Enums\FrontendComponentTarget;
use Capell\Frontend\Livewire\Page\Page;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;

final readonly class FrontendComponentRegistrar
{
    /** @param iterable<mixed> $contributors */
    public function __construct(
        private iterable $contributors,
    ) {}

    public function registerCoreComponents(FrontendComponentRegistryInterface $registry): void
    {
        foreach ([
            AssetComponentEnum::Card->value => 'capell::asset.index',
            AssetComponentEnum::Media->value => 'capell::media.asset',
            AssetComponentEnum::Page->value => 'capell::page.asset',
            AssetComponentEnum::Tile->value => 'capell::asset.tile',
        ] as $key => $component) {
            $registry->register(key: $key, component: $component, aliases: [$component]);
        }
    }

    public function registerBladeComponents(): void
    {
        foreach ($this->bladeComponents() as $name => $component) {
            Blade::component($component, $name);
        }
    }

    /** @return array<string, string> */
    public function bladeComponents(): array
    {
        return array_merge(
            $this->stringMap(config('capell-frontend.blade_components', [])),
            $this->contributedComponents(FrontendComponentTarget::Blade),
        );
    }

    /** @return array<string, class-string> */
    public function livewireComponents(): array
    {
        return $this->livewireComponentMap(array_merge(
            [LivewirePageComponentEnum::Default->value => Page::class],
            $this->stringMap(config('capell-frontend.livewire_components', [])),
            $this->contributedComponents(FrontendComponentTarget::Livewire),
        ));
    }

    /** @return array<string, string> */
    private function stringMap(mixed $configured): array
    {
        if (! is_array($configured)) {
            return [];
        }

        return array_filter(
            $configured,
            static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, string>  $components
     * @return array<string, class-string<Component>>
     */
    private function livewireComponentMap(array $components): array
    {
        return array_filter(
            $components,
            static fn (string $component): bool => is_a($component, Component::class, true),
        );
    }

    /** @return array<string, string> */
    private function contributedComponents(FrontendComponentTarget $target): array
    {
        $components = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof FrontendComponentContributor) {
                continue;
            }

            foreach ($contributor->components() as $component) {
                if (! $component instanceof FrontendComponentContributionData) {
                    continue;
                }

                if ($component->target !== $target) {
                    continue;
                }

                $components[$component->name] = $component->component;
            }
        }

        return $components;
    }
}
