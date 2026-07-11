<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands\Concerns;

use Capell\Core\Actions\AfterInstallPackageAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\SetupPackageAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\Install\ConsoleProgressReporter;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * @mixin Command
 *
 * @phpstan-type PackageActionType 'install'|'setup'|'after'
 */
trait HasPackageInstall
{
    /**
     * Shared logic for running install or setup actions on a package.
     *
     * @param  PackageActionType  $type
     * @param  list<string>|null  $languages
     * @param  list<string>|null  $sites
     * @param  array<string, mixed>|null  $assets
     */
    private function runPackageAction(
        string $type,
        PackageData $package,
        string $siteUrl,
        ?array $languages,
        ?array $sites,
        ?Authenticatable $user,
        ?array $assets = null,
    ): void {
        /** @var array<string, mixed> $params */
        $params = [];
        /** @var list<string> $paramList */
        $paramList = match ($type) {
            'install' => $package->getInstallParams(),
            'setup' => $package->getSetupParams(),
            'after' => $package->getAfterInstallParams(),
        };

        if (in_array('url', $paramList, true)) {
            $params['--url'] = $siteUrl;
        }

        if (is_array($languages) && in_array('languages', $paramList, true)) {
            $params['--languages'] = $languages;
        }

        if (is_array($sites) && in_array('sites', $paramList, true)) {
            $params['--sites'] = $sites;
        }

        if ($user instanceof Authenticatable && in_array('user', $paramList, true)) {
            $params['--user'] = (string) $user->getAuthIdentifier();
        }

        if (is_array($assets) && in_array('assets', $paramList, true)) {
            $params['--assets'] = $assets;
        }

        $reporter = new ConsoleProgressReporter($this);

        if ($type === 'install') {
            $this->line('Installing package: ' . $package->name);

            InstallPackageAction::run(package: $package, arguments: $params, reporter: $reporter);

            return;
        }

        if ($type === 'setup') {
            $this->line('Setting up package: ' . $package->name);

            SetupPackageAction::run(package: $package, arguments: $params, reporter: $reporter);

            return;
        }

        $this->line('Post-install for package: ' . $package->name);

        AfterInstallPackageAction::run(package: $package, arguments: $params, reporter: $reporter);
    }

    /**
     * Install selected packages.
     *
     * @param  Collection<string, PackageData>  $packages
     * @param  list<string>|null  $languages
     * @param  list<string>|null  $sites
     * @param  array<string, mixed>|null  $assets
     */
    private function installPackages(Collection $packages, string $siteUrl, ?array $languages = null, ?array $sites = null, ?Authenticatable $user = null, ?array $assets = null): void
    {
        $sorted = resolve(PackageWorkflowPlanner::class)->order($packages);

        $sorted->each(function (PackageData $package) use ($siteUrl, $languages, $sites, $assets, $user): void {
            $this->runPackageAction(type: 'install', package: $package, siteUrl: $siteUrl, languages: $languages, sites: $sites, user: $user, assets: $assets);
        });
    }

    /**
     * Setup selected packages.
     *
     * @param  Collection<string, PackageData>  $packages
     * @param  list<string>|null  $languages
     * @param  list<string>|null  $sites
     * @param  array<string, mixed>|null  $assets
     */
    private function setupPackages(Collection $packages, string $siteUrl, ?array $languages = null, ?array $sites = null, ?Authenticatable $user = null, ?array $assets = null): void
    {
        $sorted = resolve(PackageWorkflowPlanner::class)->order($packages);

        $sorted->each(function (PackageData $package) use ($siteUrl, $languages, $sites, $assets, $user): void {
            $this->runPackageAction(type: 'setup', package: $package, siteUrl: $siteUrl, languages: $languages, sites: $sites, user: $user, assets: $assets);
        });
    }

    /**
     * Run after-install hooks for selected packages.
     *
     * @param  Collection<string, PackageData>  $packages
     * @param  list<string>|null  $languages
     * @param  list<string>|null  $sites
     * @param  array<string, mixed>|null  $assets
     */
    private function afterInstallPackages(Collection $packages, string $siteUrl, ?array $languages = null, ?array $sites = null, ?Authenticatable $user = null, ?array $assets = null): void
    {
        resolve(PackageWorkflowPlanner::class)->order($packages)->each(function (PackageData $package) use ($siteUrl, $languages, $sites, $assets, $user): void {
            $this->runPackageAction(type: 'after', package: $package, siteUrl: $siteUrl, languages: $languages, sites: $sites, user: $user, assets: $assets);
        });
    }
}
