<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

final class PanelThemeTargetResolver
{
    /** @return list<string> */
    public function resolve(?string $adoptedProviderPath = null): array
    {
        // Explicit adoption authorises only the selected provider for this operation.
        if ($adoptedProviderPath !== null) {
            return is_file($adoptedProviderPath) ? [$adoptedProviderPath] : [];
        }

        $ownedPaths = InstallRunState::scaffoldedPanelProviders();

        return array_values(array_filter($this->providerPaths(), fn (string $path): bool => in_array(
            str($path)->after(base_path() . DIRECTORY_SEPARATOR)->toString(),
            $ownedPaths,
            true,
        )));
    }

    /** @return list<string> */
    public function providerPaths(): array
    {
        $paths = glob(app_path('Providers/Filament/*PanelProvider.php'));

        return $paths !== false ? array_values(array_filter($paths, is_file(...))) : [];
    }
}
