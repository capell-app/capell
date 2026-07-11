<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;

final class ExtensionManagementSurfaceRegistry
{
    /** @var array<string, list<ExtensionManagementSurfaceData>> */
    private array $surfaces = [];

    public function register(ExtensionManagementSurfaceData $surface): void
    {
        $surfaces = $this->surfaces[$surface->packageName] ?? [];

        foreach ($surfaces as $registeredSurface) {
            if ($registeredSurface->type === $surface->type && $registeredSurface->settingsGroup === $surface->settingsGroup) {
                return;
            }
        }

        $this->surfaces[$surface->packageName][] = $surface;
    }

    /**
     * @return list<ExtensionManagementSurfaceData>
     */
    public function surfacesForPackage(string $packageName): array
    {
        return $this->surfaces[$packageName] ?? [];
    }

    /**
     * @return list<string>
     */
    public function packageNames(): array
    {
        return array_keys($this->surfaces);
    }
}
