<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Install\WelcomeRouteInstaller;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Installer\Data\InstallerPageData;
use Capell\Installer\Support\InstallerOptions;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Role;
use Throwable;

final class BuildInstallerPageDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly InstallerOptions $options,
    ) {}

    public function handle(bool $capellAlreadyInstalled, bool $canReinstall): InstallerPageData
    {
        $packages = [];
        $packageBatches = CapellCore::getPackages(sortByDependencies: true)
            ->filter(fn (PackageData $package): bool => $package->isVisibleInCatalogue()
                || TrustedCorePackages::isDefaultInstallSelection($package->name))
            ->lazy()
            ->chunk(25);

        foreach ($packageBatches as $batch) {
            foreach ($batch as $package) {
                $packages[] = [
                    'name' => $package->name,
                    'label' => $package->getLabel(),
                    'description' => $package->getDescription(),
                    'hasFrontend' => $package->hasFrontendScope(),
                    'requirements' => $package->getRequirements(),
                    'kind' => $package->getKind(),
                    'themeKey' => $package->getThemeKey(),
                    'core' => $package->isCore(),
                    'defaultCore' => TrustedCorePackages::isDefaultInstallSelection($package->name),
                    'defaultSelected' => $this->options->packageIsDefaultSelected($package),
                ];
            }
        }

        unset($packageBatches, $batch);

        $downloadablePackages = $this->options->downloadablePackages();
        [$installId, $installStatus] = $this->sessions->activeInstallState();
        $themeOptions = $this->options->themeOptions();
        $corePackages = array_values(array_filter(
            $packages,
            fn (array $package): bool => $package['core'] && ! $this->packageArrayIsTheme($package),
        ));
        $installedPackages = array_values(array_filter(
            $packages,
            fn (array $package): bool => ! $package['core']
                && $package['name'] !== 'capell-app/installer'
                && ! $this->packageArrayIsTheme($package),
        ));

        return new InstallerPageData([
            'packages' => $packages,
            'corePackages' => $corePackages,
            'installedPackages' => $installedPackages,
            'downloadablePackages' => $downloadablePackages,
            'preflightReport' => resolve(InstallerPreflight::class)->run(),
            'languages' => $this->options->languageOptions(),
            'customLanguageSuggestions' => $this->options->customLanguageSuggestions(),
            'existingUsers' => $this->existingUserOptions(),
            'defaultSiteUrl' => (string) config('app.url'),
            'defaultLocale' => config('app.locale', 'en'),
            'allPackageNames' => array_column($packages, 'name'),
            'defaultPackageNames' => $this->defaultPackageNames($packages),
            'installableExtraPackageNames' => $this->defaultDownloadablePackageNames($downloadablePackages),
            'requirementsMap' => $this->buildRequirementsMap($packages, $downloadablePackages),
            'themeOptions' => $themeOptions,
            'themePackageNames' => $this->themePackageNames($themeOptions),
            'installedThemeKeys' => array_keys(resolve(ThemePackageCandidates::class)->optionsForInstalledPackages()),
            'defaultThemeKey' => ThemePackageCandidates::FOUNDATION_KEY,
            'showThemeSelector' => $themeOptions !== [],
            'showDemoToggle' => (bool) config('app.debug'),
            'installId' => $installId,
            'installStatus' => $installStatus,
            'cancelUrl' => $installId !== null ? route('capell-installer.cancel', ['installId' => $installId]) : null,
            'capellAlreadyInstalled' => $capellAlreadyInstalled,
            'canReinstall' => $canReinstall,
            'showFilamentPanelToggle' => $this->shouldOfferFilamentPanelInstall($packages, $downloadablePackages),
            'showWelcomeRouteToggle' => resolve(WelcomeRouteInstaller::class)->canInstall(),
            'showRoleUsersToggle' => $this->shouldShowRoleUsersToggle(),
            'developerToolingInstalled' => resolve(DeveloperToolingInstallationState::class)->isInstalled(),
            'defaultAdminUser' => $this->options->defaultAdminUser(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $packages
     * @return array<int, string>
     */
    private function defaultPackageNames(array $packages): array
    {
        return collect($packages)
            ->filter(fn (array $package): bool => (bool) ($package['defaultCore'] ?? false))
            ->map(fn (array $package): string => (string) $package['name'])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $packages
     * @param  array<int, array<string, mixed>>  $downloadablePackages
     * @return array<string, array<int, string>>
     */
    private function buildRequirementsMap(array $packages, array $downloadablePackages): array
    {
        $map = [];

        foreach ([...$packages, ...$downloadablePackages] as $package) {
            $packageName = (string) ($package['name'] ?? '');
            if ($packageName === '') {
                continue;
            }

            $map[$packageName] = array_values((array) ($package['requirements'] ?? []));
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $themeOptions
     * @return array<string, string>
     */
    private function themePackageNames(array $themeOptions): array
    {
        return collect($themeOptions)
            ->filter(fn (array $option): bool => is_string($option['packageName'] ?? null) && $option['packageName'] !== '')
            ->mapWithKeys(fn (array $option): array => [
                $option['key'] => $option['packageName'],
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $downloadablePackages
     * @return array<int, string>
     */
    private function defaultDownloadablePackageNames(array $downloadablePackages): array
    {
        return collect($downloadablePackages)
            ->filter(fn (array $package): bool => (bool) ($package['defaultCore'] ?? false)
                || in_array((string) ($package['name'] ?? ''), $this->options->configuredDefaultPackageNames(), true))
            ->map(fn (array $package): string => (string) $package['name'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function packageArrayIsTheme(array $package): bool
    {
        return is_string($package['themeKey'] ?? null) && $package['themeKey'] !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $packages
     * @param  array<int, array<string, mixed>>  $downloadablePackages
     */
    private function shouldOfferFilamentPanelInstall(array $packages, array $downloadablePackages): bool
    {
        $hasAdminPackage = collect([...$packages, ...$downloadablePackages])
            ->contains(fn (array $package): bool => ($package['name'] ?? null) === 'capell-app/admin');

        if (! $hasAdminPackage) {
            return false;
        }

        return ! $this->isFilamentPanelConfigured();
    }

    private function isFilamentPanelConfigured(): bool
    {
        if (class_exists(Filament::class)) {
            try {
                $panels = call_user_func(Filament::getPanels(...));
                if ($panels !== []) {
                    return true;
                }
            } catch (Throwable) {
                // Fall through to filesystem detection.
            }
        }

        $providersDir = app_path('Providers/Filament');

        if (! is_dir($providersDir)) {
            return false;
        }

        return glob($providersDir . '/*PanelProvider.php') !== [];
    }

    /**
     * @return array<int, array{id: int|string, label: string}>
     */
    private function existingUserOptions(): array
    {
        try {
            if (! $this->options->usersTableExists()) {
                return [];
            }

            /** @var class-string<Model> $userModel */
            $userModel = $this->options->userModel();

            return $userModel::query()
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'email'])
                ->map(fn (Model $user): array => [
                    'id' => $user->getKey(),
                    'label' => sprintf('%s <%s>', (string) $user->getAttribute('name'), (string) $user->getAttribute('email')),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function shouldShowRoleUsersToggle(): bool
    {
        if (! Schema::hasTable('roles')) {
            return true;
        }

        $existingRoleNames = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $this->starterRoleNames())
            ->pluck('name')
            ->all();

        return array_diff($this->starterRoleNames(), $existingRoleNames) !== [];
    }

    /**
     * @return array<int, string>
     */
    private function starterRoleNames(): array
    {
        return collect([
            config('capell.roles.super_admin', 'super_admin'),
            config('capell.roles.editor', 'editor'),
        ])
            ->filter(fn (mixed $roleName): bool => is_string($roleName) && $roleName !== '')
            ->unique()
            ->values()
            ->all();
    }
}
