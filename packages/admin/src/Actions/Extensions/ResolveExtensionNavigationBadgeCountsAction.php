<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionNavigationBadgeCountsData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\ExtensionHealthAlert;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Serves the Extensions navigation badge without rebuilding the full operations summary on every admin request.
 *
 * The counts are cached under a fingerprint of every input they derive from: the extension rows, the unexpired
 * health alerts visible to the current site access, the package registry and the applied migrations. Any change
 * to those inputs produces a new key, so the badge can never show counts for state that no longer exists.
 */
final class ResolveExtensionNavigationBadgeCountsAction
{
    use AsFake;
    use AsObject;

    private const string CACHE_PREFIX = 'capell:admin:extension-navigation-badge:';

    private const int CACHE_SECONDS = 3600;

    private const string REQUEST_KEY = 'capell.extension-navigation-badge-counts';

    public static function forgetRequestCache(): void
    {
        request()->attributes->remove(self::REQUEST_KEY);
    }

    public function handle(): ExtensionNavigationBadgeCountsData
    {
        $memoised = request()->attributes->get(self::REQUEST_KEY);

        if ($memoised instanceof ExtensionNavigationBadgeCountsData) {
            return $memoised;
        }

        $fingerprint = $this->fingerprint();

        if ($fingerprint === null) {
            $counts = $this->fromSummary();
        } else {
            $cached = $this->cache()->remember(
                self::CACHE_PREFIX . $fingerprint,
                self::CACHE_SECONDS,
                fn (): array => $this->fromSummary()->toArray(),
            );

            $counts = is_array($cached) ? ExtensionNavigationBadgeCountsData::from($cached) : $this->fromSummary();
        }

        request()->attributes->set(self::REQUEST_KEY, $counts);

        return $counts;
    }

    private function fromSummary(): ExtensionNavigationBadgeCountsData
    {
        $summary = BuildExtensionOperationsSummaryAction::run();

        return new ExtensionNavigationBadgeCountsData(
            unhealthyCount: $summary->unhealthyCount,
            blockedCount: $summary->blockedCount,
        );
    }

    private function fingerprint(): ?string
    {
        try {
            $siteIds = SiteAccess::current()->allowedSiteIds();

            $extensions = CapellExtension::query()->toBase()->orderBy('composer_name')->get()->all();
            $alerts = ExtensionHealthAlert::query()
                ->when($siteIds !== null, fn (Builder $query): Builder => $query
                    ->where(fn (Builder $query): Builder => $query->whereNull('affected_site_id')->orWhereIn('affected_site_id', $siteIds)))
                ->where(fn (Builder $query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()))
                ->toBase()
                ->orderBy('id')
                ->get()
                ->all();
        } catch (Throwable) {
            // Missing extension tables are a schema state the summary already reports; build it directly.
            return null;
        }

        return hash('xxh128', serialize([
            $extensions,
            $alerts,
            $this->registryFingerprint(),
            $this->installedVersionsFingerprint($extensions),
            $this->migrationsFingerprint(),
        ]));
    }

    /** @return array<string, array{0: string|null, 1: bool, 2: bool}> */
    private function registryFingerprint(): array
    {
        $packages = [];

        foreach (resolve(CapellPackageRegistry::class)->all() as $packageName => $manifest) {
            $packages[$packageName] = [
                $manifest->version,
                CapellCore::isPackageAvailable($packageName),
                CapellCore::isPackageEnabled($packageName),
            ];
        }

        ksort($packages);

        return $packages;
    }

    /**
     * Composer drift compares each extension row with the installed Composer version, which changes on deploy.
     *
     * @param  array<int, mixed>  $extensions
     * @return array<string, mixed>
     */
    private function installedVersionsFingerprint(array $extensions): array
    {
        $repository = resolve(InstalledExtensionRepository::class);

        if (! method_exists($repository, 'version')) {
            return [];
        }

        $versions = [];

        foreach ($extensions as $extension) {
            $composerName = is_object($extension) ? ($extension->composer_name ?? null) : null;

            if (is_string($composerName)) {
                $versions[$composerName] = $repository->version($composerName);
            }
        }

        return $versions;
    }

    /**
     * Required-table checks only change when migrations run, so the applied migration set stands in for them.
     *
     * @return list<string>
     */
    private function migrationsFingerprint(): array
    {
        try {
            // The framework registers the repository under its service name, not the interface.
            $repository = resolve('migration.repository');

            return $repository instanceof MigrationRepositoryInterface ? array_values($repository->getRan()) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function cache(): Repository
    {
        return resolve(Repository::class);
    }
}
