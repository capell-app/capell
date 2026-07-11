<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Data\Install\ThemeInstallOptionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Install\WelcomeRouteInstaller;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Installer\Data\InstallerPageData;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Locale;
use Lorisleiva\Actions\Concerns\AsObject;
use ResourceBundle;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Throwable;

final class BuildInstallerPageDataAction
{
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
    ) {}

    public function handle(bool $capellAlreadyInstalled, bool $canReinstall): InstallerPageData
    {
        $packages = CapellCore::getPackages(sortByDependencies: true)
            ->filter(fn (PackageData $package): bool => $package->isVisibleInCatalogue()
                || TrustedCorePackages::isDefaultInstallSelection($package->name))
            ->map(fn (PackageData $package): array => [
                'name' => $package->name,
                'label' => $package->getLabel(),
                'description' => $package->getDescription(),
                'hasFrontend' => $package->hasFrontendScope(),
                'requirements' => $package->getRequirements(),
                'kind' => $package->getKind(),
                'themeKey' => $package->getThemeKey(),
                'core' => $package->isCore(),
                'defaultCore' => TrustedCorePackages::isDefaultInstallSelection($package->name),
                'defaultSelected' => $this->packageIsDefaultSelected($package),
            ])
            ->values()
            ->all();

        $downloadablePackages = $this->fetchDownloadablePackages();
        [$installId, $installStatus] = $this->sessions->activeInstallState();
        $themeOptions = $this->themeOptions();
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
            'languages' => $this->languageOptions(),
            'customLanguageSuggestions' => $this->customLanguageSuggestions(),
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
            'defaultAdminUser' => $this->defaultAdminUser(),
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

    /** @return array<int, array<string, mixed>> */
    private function fetchDownloadablePackages(): array
    {
        try {
            return GetPluginsAction::run('download')
                ->filter(fn (PackageData $package): bool => $this->composerPackageIsAvailable($package->name))
                ->filter(fn (PackageData $package): bool => $package->isVisibleInCatalogue())
                ->reject(fn (PackageData $package): bool => $package->getThemeKey() !== null)
                ->map(fn (PackageData $package): array => [
                    'name' => $package->name,
                    'label' => $package->getLabel(),
                    'description' => $package->getDescription(),
                    'requirements' => $package->getRequirements(),
                    'core' => $package->isCore(),
                    'defaultCore' => TrustedCorePackages::isDefaultInstallSelection($package->name),
                    'defaultSelected' => $this->packageArrayIsDefaultSelected($package->toArray()),
                    'kind' => $package->getKind(),
                    'themeKey' => $package->getThemeKey(),
                    'previewImageUrl' => $package->getPreviewImageUrl(),
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, array{key: string, name: string, description: ?string, packageName: ?string, previewImageUrl: ?string}>
     */
    private function themeOptions(): array
    {
        return collect(resolve(ThemePackageCandidates::class)->optionDataForCatalogue())
            ->mapWithKeys(fn (ThemeInstallOptionData $option): array => [
                $option->key => [
                    'key' => $option->key,
                    'name' => $option->name,
                    'description' => $option->description,
                    'packageName' => $option->packageName,
                    'previewImageUrl' => $option->previewImageUrl,
                ],
            ])
            ->all();
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
                || in_array((string) ($package['name'] ?? ''), $this->configuredDefaultPackageNames(), true))
            ->map(fn (array $package): string => (string) $package['name'])
            ->values()
            ->all();
    }

    private function packageIsDefaultSelected(PackageData $package): bool
    {
        return $package->defaultSelected === true
            || in_array($package->name, $this->configuredDefaultPackageNames(), true);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function packageArrayIsDefaultSelected(array $package): bool
    {
        $packageName = (string) ($package['name'] ?? '');
        if ($this->booleanValue($package['defaultSelected'] ?? false)) {
            return true;
        }

        return $packageName !== '' && in_array($packageName, $this->configuredDefaultPackageNames(), true);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function packageArrayIsTheme(array $package): bool
    {
        return is_string($package['themeKey'] ?? null) && $package['themeKey'] !== '';
    }

    /**
     * @return array<int, string>
     */
    private function configuredDefaultPackageNames(): array
    {
        $packageNames = config('capell-installer.default_packages', []);

        if (! is_array($packageNames)) {
            return [];
        }

        return collect($packageNames)
            ->filter(fn (mixed $packageName): bool => is_string($packageName) && $packageName !== '')
            ->map(fn (string $packageName): string => $packageName)
            ->unique()
            ->values()
            ->all();
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    private function composerPackageIsAvailable(string $packageName): bool
    {
        $cacheKey = 'capell.installer.package_installable.' . hash('sha256', $packageName);
        $isTrustedCorePackage = TrustedCorePackages::contains($packageName);

        $resolver = function () use ($packageName): bool {
            $process = new Process(
                [
                    (string) config('capell-installer.composer_binary', 'composer'),
                    'require',
                    $packageName . ':*',
                    '--dry-run',
                    '--no-audit',
                    '--no-interaction',
                    '--no-progress',
                    '--no-scripts',
                    '--with-all-dependencies',
                ],
                base_path(),
                ComposerProcessEnvironment::forInstall($_SERVER),
            );
            $process->setTimeout(120);
            $process->run();

            return $process->isSuccessful();
        };

        if (! $this->sessions->cacheStoreIsUsable()) {
            return $isTrustedCorePackage && $resolver();
        }

        try {
            if (! $isTrustedCorePackage && ! Cache::has($cacheKey)) {
                return false;
            }

            return Cache::remember(
                $cacheKey,
                now()->addHour(),
                $resolver,
            );
        } catch (Throwable) {
            return $isTrustedCorePackage && $resolver();
        }
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

    /** @return array<string, string> */
    private function languageOptions(): array
    {
        $defaultLocale = $this->normaliseLanguageCode(config('app.locale', 'en'));

        return collect([$defaultLocale])
            ->merge($this->availableLanguageCodes())
            ->map(fn (string $code): string => $this->normaliseLanguageCode($code))
            ->unique()
            ->mapWithKeys(fn (string $code): array => [$code => $this->languageName($code)])
            ->all();
    }

    /** @return array<string, string> */
    private function customLanguageSuggestions(): array
    {
        return collect($this->availableLanguageCodes())
            ->mapWithKeys(fn (string $code): array => [$code => $this->languageName($code)])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function availableLanguageCodes(): array
    {
        $bundle = ResourceBundle::create('en', 'ICUDATA-lang');
        $languages = $bundle instanceof ResourceBundle ? $bundle->get('Languages') : null;

        if (! $languages instanceof ResourceBundle) {
            return ['en', 'fr', 'de', 'es', 'nl'];
        }

        return collect(iterator_to_array($languages))
            ->keys()
            ->filter(fn (string $code): bool => preg_match('/^[a-z]{2,3}$/', $code) === 1)
            ->sortBy(fn (string $code): string => $this->languageName($code))
            ->values()
            ->all();
    }

    private function normaliseLanguageCode(string $code): string
    {
        return Str::of($code)
            ->replace('_', '-')
            ->before('-')
            ->lower()
            ->toString();
    }

    private function languageName(string $code): string
    {
        $name = Locale::getDisplayLanguage($code, 'en');

        return $name !== false ? Str::headline($name) : Str::upper($code);
    }

    /**
     * @return array<int, array{id: int|string, label: string}>
     */
    private function existingUserOptions(): array
    {
        try {
            if (! $this->usersTableExists()) {
                return [];
            }

            /** @var class-string<Model> $userModel */
            $userModel = config('auth.providers.users.model');

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

    private function usersTableExists(): bool
    {
        try {
            return Schema::hasTable($this->userTable());
        } catch (Throwable) {
            return false;
        }
    }

    private function userTable(): string
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return (new $userModel)->getTable();
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

    /**
     * @return array{name: string, email: string, password: string}
     */
    private function defaultAdminUser(): array
    {
        $configured = config('capell-installer.admin_user', []);

        return [
            'name' => $this->stringValue($configured['name'] ?? null),
            'email' => $this->stringValue($configured['email'] ?? null),
            'password' => $this->stringValue($configured['password'] ?? null),
        ];
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
