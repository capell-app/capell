<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Diagnostics;

use Capell\Admin\Contracts\RegistryInspectorInterface;
use Capell\Admin\Data\Diagnostics\RegistryFlowStepData;
use Capell\Admin\Data\Diagnostics\RegistrySourceData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Core\Actions\GetComponentViewPathAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Support\Collection;
use ReflectionClass;
use Throwable;

class RegistryInspector implements RegistryInspectorInterface
{
    /**
     * @return Collection<int|string, mixed>
     */
    public function configurators(?string $configuratorType = null): Collection
    {
        $configuratorGroups = $configuratorType === null
            ? AdminSurfaceLookup::configuratorGroups()
            : [$configuratorType => CapellAdmin::getConfigurators($configuratorType)];

        /** @var Collection<int|string, mixed> $sources */
        $sources = collect($configuratorGroups)
            ->flatMap(function (array $configurators, string $type): array {
                $rows = [];

                foreach ($configurators as $key => $configuratorClass) {
                    $path = $this->classPath($configuratorClass);
                    $rows[] = new RegistrySourceData(
                        key: $key,
                        label: class_basename($configuratorClass),
                        kind: 'configurator',
                        class: $configuratorClass,
                        view: null,
                        path: $path,
                        sourcePackage: $this->sourcePackageOf($configuratorClass),
                        sourceMode: str_starts_with($configuratorClass, 'App\\') ? 'discovered' : 'registered',
                        cachePath: CapellAdmin::getConfiguratorCachePath(),
                        statePath: $type,
                        flow: collect([
                            new RegistryFlowStepData('Configurator type', $type, 'ok'),
                            new RegistryFlowStepData('Class', $configuratorClass, class_exists($configuratorClass) ? 'ok' : 'warning'),
                            new RegistryFlowStepData('Path', $path ?? 'unresolved', $path === null ? 'warning' : 'ok'),
                        ]),
                    );
                }

                return $rows;
            })
            ->values();

        return $sources;
    }

    /**
     * @return Collection<int|string, mixed>
     */
    public function components(?string $componentType = null): Collection
    {
        $componentGroups = $componentType === null
            ? CapellCore::getComponents()
            : [$componentType => CapellCore::getComponents($componentType)];

        /** @var Collection<int|string, mixed> $sources */
        $sources = collect($componentGroups)
            ->flatMap(function (mixed $components, string $type): array {
                if (! is_array($components)) {
                    return [];
                }

                $rows = [];

                foreach ($components as $label => $component) {
                    if (! is_string($component)) {
                        continue;
                    }

                    $viewPath = null;
                    $status = 'ok';

                    try {
                        $viewPath = GetComponentViewPathAction::run($component);
                    } catch (Throwable) {
                        $status = 'warning';
                    }

                    $rows[] = new RegistrySourceData(
                        key: $component,
                        label: $label,
                        kind: 'component',
                        class: null,
                        view: $component,
                        path: $viewPath,
                        sourcePackage: $viewPath !== null && str_starts_with($viewPath, base_path()) ? 'host-app' : 'package',
                        sourceMode: 'registered',
                        cachePath: CapellCore::getComponentCachePath(),
                        statePath: $type,
                        flow: collect([
                            new RegistryFlowStepData('Component type', $type, 'ok'),
                            new RegistryFlowStepData('View', $component, $status),
                        ]),
                    );
                }

                return $rows;
            })
            ->values();

        return $sources;
    }

    /**
     * @return Collection<int|string, mixed>
     */
    public function blocks(): Collection
    {
        return $this->widgets();
    }

    /**
     * @return Collection<int|string, mixed>
     */
    public function widgets(): Collection
    {
        CapellAdmin::getFilamentWidgets();

        /** @var Collection<int|string, mixed> $sources */
        $sources = collect(resolve(WidgetDiscovery::class)->registeredWidgets())
            ->map(function (string $widgetClass, string $widgetName): RegistrySourceData {
                $path = $this->classPath($widgetClass);

                return new RegistrySourceData(
                    key: $widgetName,
                    label: class_basename($widgetClass),
                    kind: 'widget',
                    class: $widgetClass,
                    view: null,
                    path: $path,
                    sourcePackage: $this->sourcePackageOf($widgetClass),
                    sourceMode: str_starts_with($widgetClass, 'App\\') ? 'discovered' : 'registered',
                    cachePath: CapellAdmin::getWidgetCachePath(),
                    statePath: 'admin-filament',
                    flow: collect([
                        new RegistryFlowStepData('Target', 'admin-filament', 'ok'),
                        new RegistryFlowStepData('Class', $widgetClass, class_exists($widgetClass) ? 'ok' : 'warning'),
                    ]),
                );
            })
            ->values();

        return $sources;
    }

    private function classPath(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        $path = new ReflectionClass($class)->getFileName();

        return is_string($path) ? $path : null;
    }

    private function sourcePackageOf(string $class): string
    {
        $map = resolve(CapellPackageRegistry::class)->namespaceMap();
        $map['App\\'] = 'host-app';

        foreach ($map as $prefix => $shortName) {
            if (str_starts_with($class, $prefix)) {
                return $shortName;
            }
        }

        return 'unknown';
    }
}
