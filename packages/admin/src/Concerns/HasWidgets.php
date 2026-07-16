<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Filament\Forms\Components\Builder\Block;

trait HasWidgets
{
    /**
     * Register a custom Filament widget class with the admin builder.
     *
     * @param  class-string  $widgetClass
     */
    public function registerWidget(string $widgetClass): static
    {
        $this->widgetDiscovery()->register($widgetClass);

        return $this;
    }

    /**
     * Register a directory for lazy widget auto-discovery.
     *
     * Classes found in $directory matching $namespace that implement
     * the content builder first resolves its widget list.
     */
    public function registerDiscoverableWidgets(string $directory, string $namespace): static
    {
        $this->widgetDiscovery()->registerDiscoverableWidgets($directory, $namespace);

        return $this;
    }

    /**
     * Return instantiated Filament widget Block objects for the content builder.
     *
     * Triggers auto-discovery (or cache restore) on first call.
     *
     * @return Block[]
     */
    // @phpstan-ignore-next-line missingType.iterableValue (Filament's Block base class is intentionally generic at this extension boundary.)
    public function getFilamentWidgets(): array
    {
        return $this->widgetDiscovery()->filamentWidgets();
    }

    public function hasCachedWidgets(): bool
    {
        return $this->widgetDiscovery()->hasCachedWidgets();
    }

    public function cacheWidgets(): void
    {
        $this->widgetDiscovery()->cacheWidgets();
    }

    public function restoreCachedWidgets(): void
    {
        $this->widgetDiscovery()->restoreCachedWidgets();
    }

    public function clearCachedWidgets(): void
    {
        $this->widgetDiscovery()->clearCachedWidgets();
    }

    public function getWidgetCachePath(): string
    {
        return $this->widgetDiscovery()->getWidgetCachePath();
    }

    private function widgetDiscovery(): WidgetDiscovery
    {
        return resolve(WidgetDiscovery::class);
    }
}
