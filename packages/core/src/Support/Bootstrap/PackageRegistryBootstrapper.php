<?php

declare(strict_types=1);

namespace Capell\Core\Support\Bootstrap;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final readonly class PackageRegistryBootstrapper
{
    public function __construct(
        private Application $app,
        private ManifestLoader $manifestLoader,
    ) {}

    public function bootstrap(): void
    {
        $registry = new CapellPackageRegistry;
        $cachePath = $this->app->bootstrapPath('cache/capell-package-manifests.php');
        $manifests = file_exists($cachePath)
            ? $this->cachedManifests($cachePath)
            : $this->manifestLoader->discover();

        $registry->fill($manifests);
        $this->app->instance(CapellPackageRegistry::class, $registry);

        foreach ($manifests as $manifest) {
            CapellCore::registerManifestPackage(
                $manifest,
                CapellCore::getInstalledPrettyVersion($manifest->name),
            );
        }

        new CapellPackageLoader($this->app, $registry)->loadProviders();
    }

    /** @return array<string, CapellManifestData> */
    private function cachedManifests(string $cachePath): array
    {
        try {
            $cached = require $cachePath;

            throw_unless(is_array($cached), InvalidManifestException::class, 'Cached Capell package manifest must return an array.');

            return array_map(
                $this->normalizeCachedManifest(...),
                $cached,
            );
        } catch (Throwable) {
            @unlink($cachePath);

            return $this->manifestLoader->discover();
        }
    }

    private function normalizeCachedManifest(mixed $manifest): CapellManifestData
    {
        if ($manifest instanceof CapellManifestData) {
            return $manifest;
        }

        throw_unless(is_array($manifest), InvalidManifestException::class, 'Cached Capell package entries must be manifest arrays.');

        return CapellManifestData::fromArray($manifest);
    }
}
