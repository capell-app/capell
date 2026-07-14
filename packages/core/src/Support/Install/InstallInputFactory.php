<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Support\Collection;

final class InstallInputFactory
{
    public function __construct(private readonly PackageWorkflowPlanner $packageWorkflowPlanner) {}

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $defaultPackageNames
     */
    public function fromWebInput(array $validated, bool $allowWelcomeRoute = true, array $defaultPackageNames = []): InstallInputData
    {
        $adminUserMode = (string) ($validated['admin_user_mode'] ?? 'create');
        $existingUserId = $adminUserMode === 'existing' ? (int) $validated['existing_user_id'] : null;
        $newUser = $adminUserMode === 'create'
            ? new NewUserData(
                name: (string) $validated['new_user_name'],
                email: (string) $validated['new_user_email'],
                password: (string) $validated['new_user_password'],
            )
            : null;

        $demoContent = (bool) ($validated['demo_content'] ?? false);
        $packageSelectionMode = $this->packageSelectionMode($validated);
        $availablePackages = CapellCore::getPackages();
        $selectedPackages = $this->packageWorkflowPlanner
            ->expandAndOrder(
                $availablePackages,
                $this->selectedPackageNames($availablePackages, $validated, $packageSelectionMode, $defaultPackageNames),
                (bool) ($validated['fresh_install'] ?? false),
            )
            ->keys()
            ->all();
        if ($demoContent) {
            $selectedPackages = $this->includeDemoPackages(
                $availablePackages,
                $selectedPackages,
                (bool) ($validated['fresh_install'] ?? false),
            );
        }

        $extraPackages = $this->stringList($validated['extra_packages'] ?? []);
        $selectedThemeKey = is_string($validated['theme'] ?? null) && $validated['theme'] !== ''
            ? $validated['theme']
            : ThemePackageCandidates::FOUNDATION_KEY;
        [$selectedPackages, $extraPackages] = $this->includeSelectedThemePackage($selectedThemeKey, $selectedPackages, $extraPackages);
        $hasFrontend = collect($selectedPackages)
            ->contains(fn (string $packageName): bool => CapellCore::getPackages()->get($packageName)?->hasFrontendScope() ?? false);
        $selectedInstallPackageNames = array_values(array_unique([
            ...$selectedPackages,
            ...$extraPackages,
        ]));
        $hasAdminPackage = in_array('capell-app/admin', $selectedInstallPackageNames, true);
        $adminPanelChangesMode = is_string($validated['admin_panel_changes_mode'] ?? null)
            ? $validated['admin_panel_changes_mode']
            : null;
        $autoApplyAdminPanelChanges = $this->shouldAutoApplyAdminPanelChanges($hasAdminPackage, $adminPanelChangesMode, $validated);
        $developerToolingIsInstalled = resolve(DeveloperToolingInstallationState::class)->isInstalled();
        $installDeveloperTooling = $developerToolingIsInstalled || (bool) ($validated['install_developer_tooling'] ?? false);
        $additionalUsers = (bool) ($validated['create_role_users'] ?? false)
            ? $this->exampleRoleUsers($this->roleUserPassword($validated, $newUser))
            : [];

        return new InstallInputData(
            siteUrl: (string) $validated['site_url'],
            packages: $selectedPackages,
            languages: [(string) $validated['language']],
            demoContent: $demoContent,
            cachesToClear: [],
            generateSitemap: (bool) ($validated['generate_sitemap'] ?? false),
            generateStaticSite: false,
            demoSites: $demoContent ? [config('app.name', 'Capell Application')] : null,
            demoLanguages: $demoContent ? [(string) $validated['language']] : null,
            userId: $existingUserId,
            newUser: $newUser,
            seedDefaultData: $demoContent || (bool) ($validated['seed_default_data'] ?? false),
            installFilamentPanel: (bool) ($validated['install_filament_panel'] ?? false),
            extraPackages: $extraPackages,
            integrateAdminPanel: $autoApplyAdminPanelChanges,
            adminDiscoverSchemas: [],
            adminAddColors: $this->shouldApplyAdminPanelChange($hasAdminPackage, $adminPanelChangesMode, $autoApplyAdminPanelChanges, $validated, 'admin_add_colors'),
            adminAddWidgets: $this->shouldApplyAdminPanelChange($hasAdminPackage, $adminPanelChangesMode, $autoApplyAdminPanelChanges, $validated, 'admin_add_widgets'),
            adminAddNavigation: $this->shouldApplyAdminPanelChange($hasAdminPackage, $adminPanelChangesMode, $autoApplyAdminPanelChanges, $validated, 'admin_add_navigation'),
            rebuildResources: (bool) ($validated['rebuild_resources'] ?? false),
            freshInstall: (bool) ($validated['fresh_install'] ?? false),
            installWelcomeRoute: $allowWelcomeRoute && $this->shouldInstallWelcomeRoute($hasFrontend, $validated),
            installDeveloperTooling: $installDeveloperTooling,
            configureBoostDeveloperTooling: $installDeveloperTooling && (bool) ($validated['configure_boost_developer_tooling'] ?? false),
            additionalUsers: $additionalUsers,
            selectedThemeKey: resolve(ThemePackageCandidates::class)->inputThemeKey($selectedThemeKey),
        );
    }

    /**
     * @param  array<string>  $packages
     * @param  array<string>  $languages
     * @param  array<string>  $cachesToClear
     * @param  array<string>|null  $demoSites
     * @param  array<string>|null  $demoLanguages
     * @param  array{css: string, js: string}|null  $assets
     * @param  array<NewUserData>  $additionalUsers
     * @param  array<string>  $extraPackages
     */
    public function fromResolvedConsoleInput(
        string $siteUrl,
        array $packages,
        array $languages,
        bool $demoContent,
        array $cachesToClear,
        bool $generateSitemap,
        bool $generateStaticSite,
        ?array $demoSites = null,
        ?array $demoLanguages = null,
        ?array $assets = null,
        ?int $userId = null,
        ?NewUserData $newUser = null,
        bool $seedDefaultData = true,
        bool $seedDatabase = false,
        bool $freshInstall = false,
        bool $installWelcomeRoute = false,
        bool $installDeveloperTooling = false,
        bool $configureBoostDeveloperTooling = false,
        array $additionalUsers = [],
        ?string $selectedThemeKey = null,
        array $extraPackages = [],
    ): InstallInputData {
        return new InstallInputData(
            siteUrl: $siteUrl,
            packages: $packages,
            languages: $languages,
            demoContent: $demoContent,
            cachesToClear: $cachesToClear,
            generateSitemap: $generateSitemap,
            generateStaticSite: $generateStaticSite,
            demoSites: $demoSites,
            demoLanguages: $demoLanguages,
            assets: $assets,
            userId: $userId,
            newUser: $newUser,
            seedDefaultData: $demoContent || $seedDefaultData,
            extraPackages: $extraPackages,
            freshInstall: $freshInstall,
            installWelcomeRoute: $installWelcomeRoute,
            installDeveloperTooling: $installDeveloperTooling,
            configureBoostDeveloperTooling: $configureBoostDeveloperTooling,
            additionalUsers: $additionalUsers,
            selectedThemeKey: $selectedThemeKey,
            seedDatabase: $seedDatabase,
        );
    }

    /**
     * @return array<NewUserData>
     */
    public function exampleRoleUsers(string $password): array
    {
        $roleName = config('capell.roles.super_admin', 'super_admin');
        $superAdminRoleName = is_string($roleName) && $roleName !== '' ? $roleName : 'super_admin';

        return [
            new NewUserData(
                name: 'Example Super Admin',
                email: 'super-admin@example.test',
                password: $password,
                roleName: $superAdminRoleName,
            ),
            new NewUserData(
                name: 'Example Editor',
                email: 'editor@example.test',
                password: $password,
                roleName: 'editor',
            ),
        ];
    }

    /**
     * @param  Collection<string, PackageData>  $availablePackages
     * @param  array<int, string>  $selectedPackageNames
     * @return array<int, string>
     */
    private function includeDemoPackages(Collection $availablePackages, array $selectedPackageNames, bool $includeInstalledRequirements): array
    {
        $demoPackageNames = $availablePackages
            ->filter(fn (PackageData $package): bool => $package->isDemo())
            ->keys();

        if ($demoPackageNames->isEmpty()) {
            return $selectedPackageNames;
        }

        $withDemoPackages = collect($selectedPackageNames)
            ->merge($demoPackageNames)
            ->unique()
            ->values()
            ->all();

        if ($withDemoPackages === $selectedPackageNames) {
            return $selectedPackageNames;
        }

        return $this->packageWorkflowPlanner
            ->expandAndOrder($availablePackages, $withDemoPackages, $includeInstalledRequirements)
            ->keys()
            ->all();
    }

    /**
     * @param  array<int, string>  $selectedPackageNames
     * @param  array<int, string>  $extraPackageNames
     * @return array{array<int, string>, array<int, string>}
     */
    private function includeSelectedThemePackage(string $selectedThemeKey, array $selectedPackageNames, array $extraPackageNames): array
    {
        $themeOptions = resolve(ThemePackageCandidates::class)
            ->optionDataForCatalogue();
        $packageName = $themeOptions[$selectedThemeKey]->packageName ?? null;

        if ($packageName === null) {
            return [$selectedPackageNames, $extraPackageNames];
        }

        if (CapellCore::hasPackage($packageName)) {
            $selectedPackageNames[] = $packageName;

            return [array_values(array_unique($selectedPackageNames)), $extraPackageNames];
        }

        $extraPackageNames[] = $packageName;

        return [$selectedPackageNames, array_values(array_unique($extraPackageNames))];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function roleUserPassword(array $validated, ?NewUserData $newUser): string
    {
        $password = $validated['role_user_password'] ?? null;

        if (is_string($password) && $password !== '') {
            return $password;
        }

        return (string) $newUser?->password;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function shouldInstallWelcomeRoute(bool $hasFrontend, array $validated): bool
    {
        return $hasFrontend
            && (bool) ($validated['install_welcome_route'] ?? false)
            && resolve(WelcomeRouteInstaller::class)->canInstall();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function packageSelectionMode(array $validated): string
    {
        $mode = $validated['package_selection_mode'] ?? 'core';

        return in_array($mode, ['core', 'all', 'custom'], true) ? $mode : 'core';
    }

    /**
     * @param  Collection<string, PackageData>  $availablePackages
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $defaultPackageNames
     * @return array<int, string>
     */
    private function selectedPackageNames(Collection $availablePackages, array $validated, string $packageSelectionMode, array $defaultPackageNames): array
    {
        if ($packageSelectionMode === 'all') {
            return $availablePackages
                ->filter(fn (PackageData $package): bool => $package->isVisibleInCatalogue())
                ->keys()
                ->all();
        }

        if ($packageSelectionMode === 'core') {
            return $availablePackages
                ->filter(fn (PackageData $package): bool => TrustedCorePackages::isDefaultInstallSelection($package->name)
                    || in_array($package->name, $defaultPackageNames, true))
                ->keys()
                ->all();
        }

        return $this->stringList($validated['packages'] ?? []);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        return collect((array) $value)
            ->filter(fn (mixed $item): bool => is_string($item) && $item !== '')
            ->map(fn (string $item): string => $item)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function shouldAutoApplyAdminPanelChanges(bool $hasAdminPackage, ?string $adminPanelChangesMode, array $validated): bool
    {
        if (! $hasAdminPackage) {
            return false;
        }

        if ($adminPanelChangesMode !== null) {
            return $adminPanelChangesMode === 'auto';
        }

        return (bool) ($validated['integrate_admin_panel'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function shouldApplyAdminPanelChange(
        bool $hasAdminPackage,
        ?string $adminPanelChangesMode,
        bool $autoApplyAdminPanelChanges,
        array $validated,
        string $field,
    ): bool {
        if (! $hasAdminPackage || ! $autoApplyAdminPanelChanges) {
            return false;
        }

        if ($adminPanelChangesMode !== null) {
            return true;
        }

        return (bool) ($validated[$field] ?? false);
    }
}
