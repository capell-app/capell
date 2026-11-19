<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Marketplace\CommercialCatalogueStore;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Capell\Core\Support\Marketplace\SignedPackageLicenceManifest;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Override;
use Throwable;

final class RuntimeRolePackageManifest extends PackageManifest
{
    private readonly string $runtimeManifestPath;

    private readonly ComposerInstallIdentity $identity;

    /** @var array<string, array{providers: list<string>, aliases: array<string, string>}> */
    private array $deferredDiscovery = [];

    public function __construct(
        Filesystem $files,
        string $basePath,
        string $manifestPath,
        private readonly string $sourceManifestPath,
        private readonly RuntimeRole $role,
        private readonly RuntimeRoleProviderPolicy $policy,
    ) {
        parent::__construct($files, $basePath, $manifestPath);
        $this->runtimeManifestPath = $manifestPath;
        $this->identity = new ComposerInstallIdentity($files, $basePath);
    }

    #[Override]
    public function build(): void
    {
        $snapshot = $this->identity->snapshot();

        new PackageManifest(
            $this->files,
            $this->basePath,
            $this->sourceManifestPath,
        )->build();
        $this->invalidateOpcache($this->sourceManifestPath);
        $this->identity->record($this->sourceIdentityPath(), $snapshot);

        $this->manifest = $this->filteredSourceManifest();
        $this->persistRuntimeManifest($this->manifest, $snapshot);
    }

    /** @return array{providers: list<string>, aliases: array<string, string>} */
    public function deferredDiscoveryFor(string $package): array
    {
        $this->getManifest();

        return $this->deferredDiscovery[$package] ?? ['providers' => [], 'aliases' => []];
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getManifest(): array
    {
        if (is_array($this->manifest)) {
            return $this->manifest;
        }

        // Only the source cache contains the full Composer discovery list.
        $enforcing = $this->commercialPolicy()->enforcing();
        $cachePath = $enforcing ? $this->sourceManifestPath : $this->runtimeManifestPath;
        $identityPath = $enforcing ? $this->sourceIdentityPath() : $this->runtimeIdentityPath();
        if (is_file($cachePath) && $this->identity->matches($identityPath)) {
            $manifest = $this->files->getRequire($cachePath);

            if (is_array($manifest)) {
                return $this->manifest = $this->filterManifest($manifest);
            }
        }

        // Taken before any rebuild so a change made meanwhile is caught on the next boot.
        $snapshot = $this->identity->snapshot();

        if (! is_file($this->sourceManifestPath) || ! $this->identity->matches($this->sourceIdentityPath())) {
            try {
                new PackageManifest(
                    $this->files,
                    $this->basePath,
                    $this->sourceManifestPath,
                )->build();
                $this->invalidateOpcache($this->sourceManifestPath);
                $this->identity->record($this->sourceIdentityPath(), $snapshot);
            } catch (Throwable) {
                // A read-only bootstrap/cache cannot hold the rebuilt source manifest, and the
                // existing one is known to be stale: derive the current manifest in memory.
                return $this->manifest = $this->filterManifest($this->buildInMemory());
            }
        }

        $manifest = $this->filteredSourceManifest();
        $this->persistRuntimeManifest($manifest, $snapshot);

        return $this->manifest = $manifest;
    }

    /** @return array<string, mixed> */
    private function buildInMemory(): array
    {
        // A unique temporary path keeps opcache from serving an earlier manifest.
        $temporary = sys_get_temp_dir() . '/capell-package-manifest-' . bin2hex(random_bytes(8)) . '.php';

        try {
            new PackageManifest($this->files, $this->basePath, $temporary)->build();
            $manifest = $this->files->getRequire($temporary);
        } finally {
            $this->files->delete($temporary);
        }

        return is_array($manifest) ? $manifest : [];
    }

    private function runtimeIdentityPath(): string
    {
        return dirname($this->runtimeManifestPath) . '/install-identity.php';
    }

    private function sourceIdentityPath(): string
    {
        return dirname($this->sourceManifestPath) . '/capell-runtime/source-install-identity.php';
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{sha256: string, size: int, mtime: int, generated_at: int}|null  $snapshot
     */
    private function persistRuntimeManifest(array $manifest, ?array $snapshot): void
    {
        // Keep commercial omissions out of the role cache used when enforcement is off.
        // An empty manifest may come from a half-written installed.json.
        if ($manifest === [] || $this->commercialPolicy()->enforcing()) {
            return;
        }

        try {
            $this->files->ensureDirectoryExists(dirname($this->runtimeManifestPath));
            $this->files->replace(
                $this->runtimeManifestPath,
                '<?php return ' . var_export($manifest, return: true) . ';' . PHP_EOL,
            );
            $this->invalidateOpcache($this->runtimeManifestPath);
            $this->identity->record($this->runtimeIdentityPath(), $snapshot);
        } catch (Throwable) {
            // A read-only bootstrap/cache must still boot from the in-memory manifest.
        }
    }

    /** @return array<string, mixed> */
    private function filteredSourceManifest(): array
    {
        $source = is_file($this->sourceManifestPath)
            ? $this->files->getRequire($this->sourceManifestPath)
            : [];

        if (! is_array($source)) {
            return [];
        }

        return $this->filterManifest($source);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function filterManifest(array $source): array
    {
        $manifest = [];
        $this->deferredDiscovery = [];
        $commercial = $this->commercialPolicy();
        $enforcing = $commercial->enforcing();
        $registered = $enforcing ? $this->cachedCapellManifestNames() : null;

        foreach ($source as $package => $configuration) {
            if (! is_string($package)) {
                continue;
            }

            if (! is_array($configuration)) {
                continue;
            }

            $filtered = $this->policy->filterLaravelPackage($package, $configuration, $this->role);
            if (! is_array($filtered)) {
                continue;
            }

            $hasManifest = $enforcing && $this->hasCapellManifest($package, $registered);
            if ($enforcing && ! $hasManifest && $commercial->blocksLaravelDiscoveryWithoutManifest($package)) {
                continue;
            }

            if (! $this->deferCommercialExtension($package, $hasManifest, $commercial)) {
                $manifest[$package] = $filtered;

                continue;
            }

            $providers = $filtered['providers'] ?? [];
            $aliases = $filtered['aliases'] ?? [];
            $this->deferredDiscovery[$package] = [
                'providers' => is_array($providers) ? array_values(array_filter($providers, is_string(...))) : [],
                'aliases' => is_array($aliases) ? array_filter(
                    $aliases,
                    static fn (mixed $class, int|string $alias): bool => is_string($alias) && is_string($class),
                    ARRAY_FILTER_USE_BOTH,
                ) : [],
            ];
        }

        return $manifest;
    }

    private function commercialPolicy(): ExtensionCommercialPolicy
    {
        // Discovery precedes the registration of Core's service provider.
        $app = app();
        $app->singletonIf(CommercialCatalogueStore::class);
        $app->singletonIf(SignedPackageLicenceManifest::class);
        $app->singletonIf(ExtensionCommercialPolicy::class);

        return $app->make(ExtensionCommercialPolicy::class);
    }

    /**
     * The manifest-v3 loader can replay discovery for Capell extensions after checking their
     * lifecycle records. Plain Laravel packages use early discovery, with only the existing
     * commercial discovery block applying to unauthorised recognised identities.
     */
    private function deferCommercialExtension(string $package, bool $hasManifest, ExtensionCommercialPolicy $commercial): bool
    {
        return $hasManifest && (! TrustedCorePackages::contains($package) || $commercial->requiresAuthorisation($package));
    }

    /** @param array<string, true>|null $registered */
    private function hasCapellManifest(string $package, ?array $registered): bool
    {
        $installedPath = InstalledVersions::isInstalled($package) ? InstalledVersions::getInstallPath($package) : null;
        $installedPath ??= resolve(InstalledExtensionRepository::class)->vendorDirectory() . '/' . $package;
        $path = $installedPath . '/capell.json';

        if (! is_file($path)) {
            return false;
        }

        if ($registered !== null) {
            return isset($registered[$package]);
        }

        // On-demand discovery only admits v3 manifests.
        $contents = @file_get_contents($path);
        $manifest = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($manifest) && ($manifest['manifest-version'] ?? null) === 3;
    }

    /** @return array<string, true>|null Null when the registry is discovered on demand. */
    private function cachedCapellManifestNames(): ?array
    {
        $cachePath = dirname($this->sourceManifestPath) . '/capell-package-manifests.php';
        if (! is_file($cachePath)) {
            return null;
        }

        // Match PackageRegistryBootstrapper: outside production, a newer Composer install
        // record makes the Capell registry rediscover packages instead of using its cache.
        $installedPath = $this->basePath . '/vendor/composer/installed.json';
        $app = app();
        if ((! $app->bound('env') || ! $app->environment('production')) && is_file($installedPath)) {
            clearstatcache(true, $installedPath);
            clearstatcache(true, $cachePath);

            if (filemtime($installedPath) > filemtime($cachePath)) {
                return null;
            }
        }

        try {
            $cached = $this->files->getRequire($cachePath);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($cached)) {
            return null;
        }

        $registered = [];
        foreach ($cached as $manifest) {
            $name = $manifest instanceof CapellManifestData ? $manifest->name : (is_array($manifest) ? ($manifest['name'] ?? null) : null);
            if (! is_string($name)) {
                return null;
            }

            $registered[$name] = true;
        }

        return $registered;
    }

    private function invalidateOpcache(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
