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

        if ($this->canDiscoverOnDemand()) {
            return $this->manifestLoader->discover();
        }

        throw new RuntimeException('The Capell package manifest cache is missing. Run [php artisan capell:package-cache] during deployment.');
    }

    /**
     * Discovery walks every installed package's manifest, which is far too
     * expensive to repeat on each web request — so production keeps the hard
     * failure, making a skipped deploy step loud rather than quietly slow.
     * Outside production that cost does not matter and a 500 on every page
     * does, so rebuild on demand instead.
     */
    private function canDiscoverOnDemand(): bool
    {
        if ($this->app->runningInConsole()) {
            return true;
        }

        return ! $this->app->environment('production');
    }

    /** @return array<string, CapellManifestData> */
    private function cachedManifests(string $cachePath): array
    {
        try {
            return $this->readCachedManifests($cachePath);
        } catch (Throwable) {
            // A first failure is ambiguous. The file may be genuinely corrupt,
            // or the read may have been torn — truncated reads happen on
            // virtualised and network filesystems, and surface as a ParseError
            // past the end of the file, exactly like real corruption. Deleting
            // on the first failure turns a retryable glitch into an outage that
            // lasts until someone reruns the command by hand, so re-read once:
            // a torn read will not reproduce, genuine corruption will.
        }

        clearstatcache(true, $cachePath);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cachePath, true);
        }

        try {
            return $this->readCachedManifests($cachePath);
        } catch (Throwable $throwable) {
            @unlink($cachePath);

            if ($this->canDiscoverOnDemand()) {
                return $this->manifestLoader->discover();
            }

            throw new RuntimeException('The Capell package manifest cache is invalid. Run [php artisan capell:package-cache] during deployment.', $throwable->getCode(), previous: $throwable);
        }
    }

    /** @return array<string, CapellManifestData> */
    private function readCachedManifests(string $cachePath): array
    {
        $cached = require $cachePath;

        throw_unless(is_array($cached), InvalidManifestException::class, 'Cached Capell package manifest must return an array.');

        return array_map(
            $this->normalizeCachedManifest(...),
            $cached,
        );
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
