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
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

final readonly class FrontendComponentRegistrar
{
    /** @param iterable<mixed> $contributors */
    public function __construct(
        private Application $application,
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
        foreach ($this->stringMap(config('capell-frontend.blade_components', [])) as $name => $component) {
            Blade::component($component, $name);
        }

        foreach ($this->contributedComponents(FrontendComponentTarget::Blade) as $name => $component) {
            Blade::component($component, $name);
        }
    }

    public function registerLivewireComponents(): void
    {
        if (! $this->application->bound('livewire.finder')) {
            return;
        }

        Livewire::component(LivewirePageComponentEnum::Default->value, Page::class);

        foreach ($this->stringMap(config('capell-frontend.livewire_components', [])) as $name => $component) {
            Livewire::component($name, $component);
        }

        foreach ($this->contributedComponents(FrontendComponentTarget::Livewire) as $name => $component) {
            Livewire::component($name, $component);
        }
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

    /** @return array<string, string> */
    private function contributedComponents(FrontendComponentTarget $target): array
    {
        $components = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof FrontendComponentContributor) {
                continue;
            }

            foreach ($contributor->components() as $component) {
                if (! $component instanceof FrontendComponentContributionData || $component->target !== $target) {
                    continue;
                }

                $components[$component->name] = $component->component;
            }
        }

        return $components;
    }
}
