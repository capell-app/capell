<?php

declare(strict_types=1);

namespace Capell\Core\Support\Composer;

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Throwable;

final class InstallPackageArguments
{
    /** @param list<string> $packages
     * @return list<string>
     */
    public function resolve(array $packages): array
    {
        try {
            $catalogue = GetPluginsAction::run('download');
        } catch (Throwable) {
            // Composer remains the authority when the catalogue is unavailable.
            $catalogue = collect();
        }

        return array_map(function (string $name) use ($catalogue): string {
            if (str_contains($name, ':')) {
                return $name;
            }

            $package = $catalogue->get($name);
            if (! $package instanceof PackageData && CapellCore::hasPackage($name)) {
                $package = CapellCore::getPackage($name);
            }

            $version = $package instanceof PackageData ? $package->version : null;

            // Allow only this package's advertised prerelease, never lower the
            // host's minimum-stability or opt every dependency into dev versions.
            return is_string($version) && preg_match('/^v?\d+\.\d+\.\d+-(?:alpha|beta|rc)[.\d]*$/iD', $version) === 1
                ? $name . ':^' . ltrim($version, 'v')
                : $name;
        }, $packages);
    }
}
