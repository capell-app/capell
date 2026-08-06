<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\DashboardRegionEnum;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Filament\Widgets\Widget;
use Throwable;

/** @extends AbstractKeyedRegistry<class-string<Widget>> */
class DashboardFilamentWidgetRegistry extends AbstractKeyedRegistry
{
    /** @var array<string, DashboardRegionEnum> */
    private array $regions = [];

    /** @param class-string<Widget> $widgetClass */
    public function register(string $widgetClass, DashboardEnum ...$dashboards): void
    {
        foreach ($dashboards as $dashboard) {
            $key = $dashboard->value . ':' . $widgetClass;
            $this->setItem($key, $widgetClass);
            $this->regions[$key] ??= DashboardRegionEnum::Additional;
        }
    }

    /**
     * Register a widget with an explicit composed-dashboard region.
     *
     * @param  class-string<Widget>  $widgetClass
     */
    public function registerPanel(DashboardRegionEnum $region, string $widgetClass, DashboardEnum ...$dashboards): void
    {
        $this->register($widgetClass, ...$dashboards);

        foreach ($dashboards as $dashboard) {
            $this->regions[$dashboard->value . ':' . $widgetClass] = $region;
        }
    }

    /** @return list<class-string<Widget>> */
    public function forDashboard(DashboardEnum $dashboard): array
    {
        $prefix = $dashboard->value . ':';

        $widgets = array_values(array_filter(
            $this->allItems(),
            static fn (string $key): bool => str_starts_with($key, $prefix),
            ARRAY_FILTER_USE_KEY,
        ));

        usort($widgets, $this->compare(...));

        return $widgets;
    }

    /** @return list<class-string<Widget>> */
    public function forDashboardRegion(DashboardEnum $dashboard, DashboardRegionEnum $region): array
    {
        return array_values(array_filter(
            $this->forDashboard($dashboard),
            fn (string $widgetClass): bool => ($this->regions[$dashboard->value . ':' . $widgetClass] ?? DashboardRegionEnum::Additional) === $region,
        ));
    }

    public function regionFor(DashboardEnum $dashboard, string $widgetClass): DashboardRegionEnum
    {
        return $this->regions[$dashboard->value . ':' . $widgetClass] ?? DashboardRegionEnum::Additional;
    }

    /** @param class-string<Widget> $first @param class-string<Widget> $second */
    private function compare(string $first, string $second): int
    {
        $firstDefault = $this->defaultSort($first);
        $secondDefault = $this->defaultSort($second);
        $firstPinned = $firstDefault < 0;
        $secondPinned = $secondDefault < 0;

        if ($firstPinned || $secondPinned) {
            if ($firstPinned !== $secondPinned) {
                return $firstPinned ? -1 : 1;
            }

            return ($firstDefault <=> $secondDefault) ?: ($first <=> $second);
        }

        $firstConfigured = $this->configuredSort($first);
        $secondConfigured = $this->configuredSort($second);

        if ($firstConfigured !== null && $secondConfigured !== null) {
            return ($firstConfigured <=> $secondConfigured) ?: $this->compareDefaults($first, $second);
        }

        if ($firstConfigured !== null || $secondConfigured !== null) {
            return $firstConfigured !== null ? -1 : 1;
        }

        return $this->compareDefaults($first, $second);
    }

    private function compareDefaults(string $first, string $second): int
    {
        return ($this->defaultSort($first) <=> $this->defaultSort($second)) ?: ($first <=> $second);
    }

    private function configuredSort(string $widgetClass): ?int
    {
        $settingsKey = $this->settingsKey($widgetClass);

        if ($settingsKey === '') {
            return null;
        }

        try {
            $settings = resolve(AdminSettings::class);
        } catch (Throwable) {
            return null;
        }

        return array_key_exists($settingsKey, $settings->widget_order)
            ? $settings->sortOrderFor($settingsKey)
            : null;
    }

    private function defaultSort(string $widgetClass): int
    {
        if (! is_callable([$widgetClass, 'getSort'])) {
            return PHP_INT_MAX;
        }

        $sort = forward_static_call([$widgetClass, 'getSort']);

        return is_int($sort) ? $sort : PHP_INT_MAX;
    }

    private function settingsKey(string $widgetClass): string
    {
        if (! class_exists($widgetClass) || ! method_exists($widgetClass, 'settingsKey')) {
            return '';
        }

        try {
            $settingsKey = $widgetClass::settingsKey();
        } catch (Throwable) {
            return '';
        }

        return is_string($settingsKey) ? $settingsKey : '';
    }
}
