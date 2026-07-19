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
use RuntimeException;
use Throwable;

final readonly class PackageRegistryBootstrapper
{
    public function __construct(
        private Application $app,
        private ManifestLoader $manifestLoader,
    ) {}

    public function bootstrap(): void
    {
        $registry = $this->app->make(CapellPackageRegistry::class);
        $cachePath = $this->app->bootstrapPath('cache/capell-package-manifests.php');
        $manifests = $this->manifests($cachePath);

        $registry->fill([
            ...$registry->all(),
            ...$manifests,
        ]);
        foreach ($manifests as $manifest) {
            CapellCore::registerManifestPackage(
                $manifest,
                CapellCore::getInstalledPrettyVersion($manifest->name),
            );
        }

        new CapellPackageLoader($this->app, $registry)->loadProviders();
    }

    /** @return array<string, CapellManifestData> */
    private function manifests(string $cachePath): array
    {
        if (file_exists($cachePath)) {
            return $this->cachedManifests($cachePath);
        }

        if ($this->app->runningInConsole()) {
            return $this->manifestLoader->discover();
        }

        throw new RuntimeException('The Capell package manifest cache is missing. Run [php artisan capell:package-cache] during deployment.');
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
        } catch (Throwable $throwable) {
            @unlink($cachePath);

            if ($this->app->runningInConsole()) {
                return $this->manifestLoader->discover();
            }

            throw new RuntimeException('The Capell package manifest cache is invalid. Run [php artisan capell:package-cache] during deployment.', $throwable->getCode(), previous: $throwable);
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
