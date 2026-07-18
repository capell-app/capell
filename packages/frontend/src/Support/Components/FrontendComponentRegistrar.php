<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Components;

use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Enums\LivewirePageComponentEnum;
use Capell\Frontend\Contracts\FrontendComponentRegistryInterface;
use Capell\Frontend\Livewire\Page\Page;
use Capell\LayoutBuilder\Enums\LayoutWidgetTarget;
use Capell\LayoutBuilder\Support\LayoutWidgets\LayoutWidgetRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;

final readonly class FrontendComponentRegistrar
{
    public function __construct(
        private Application $application,
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

        foreach ($this->layoutWidgets('FrontendBlade') as $name => $component) {
            Blade::component($component, $name);
        }
    }

    /** @return array<string, class-string> */
    public function livewireComponents(): array
    {
        return $this->livewireComponentMap(array_merge(
            [LivewirePageComponentEnum::Default->value => Page::class],
            $this->stringMap(config('capell-frontend.livewire_components', [])),
            $this->layoutWidgets('FrontendLivewire'),
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
    private function layoutWidgets(string $target): array
    {
        if (! class_exists(LayoutWidgetRegistry::class) || ! enum_exists(LayoutWidgetTarget::class)) {
            return [];
        }

        $layoutWidgetTarget = $target === 'FrontendBlade'
            ? LayoutWidgetTarget::FrontendBlade
            : LayoutWidgetTarget::FrontendLivewire;

        return $this->application
            ->make(LayoutWidgetRegistry::class)
            ->allForTarget($layoutWidgetTarget);
    }
}
