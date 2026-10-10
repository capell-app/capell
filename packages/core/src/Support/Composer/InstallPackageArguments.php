<?php

declare(strict_types=1);

namespace Capell\Core\Support\Composer;

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use JsonException;
use Throwable;

final class InstallPackageArguments
{
    public function __construct(private readonly ?string $applicationPath = null) {}

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

        $arguments = array_map(function (string $name) use ($catalogue): string {
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
        if ($packages !== [] && ! in_array('capell-app/core', array_map(fn (string $name): string => explode(':', $name, 2)[0], $packages), true)) {
            $localCore = $this->localCoreRequirement();
            if ($localCore !== null) {
                $arguments[] = $localCore;
            }
        }

        return $arguments;
    }

    private function localCoreRequirement(): ?string
    {
        $path = $this->applicationPath ?? base_path();
        if (! is_file($path . '/composer.json') || ! is_file($path . '/composer.lock')) {
            return null;
        }

        try {
            $root = json_decode((string) file_get_contents($path . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            $lock = json_decode((string) file_get_contents($path . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $constraint = is_array($root) && is_array($root['require'] ?? null) ? $root['require']['capell-app/core'] ?? null : null;
        $packages = is_array($lock) ? $lock['packages'] ?? null : null;
        if (! is_string($constraint) || $constraint === '' || ! is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'capell-app/core'
                && is_array($package['dist'] ?? null) && ($package['dist']['type'] ?? null) === 'path') {
                // Composer's partial update can exclude a locked path package even with -W.
                // Explicitly unlock Core while preserving the host's existing constraint.
                return 'capell-app/core:' . $constraint;
            }
        }

        return null;
    }
}
