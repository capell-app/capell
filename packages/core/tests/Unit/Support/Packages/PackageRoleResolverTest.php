<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageRole;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\PackageRoleResolver;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    CapellCore::clearPackages();
});

it('finds installed packages by the role they declare and orders them by priority then name', function (): void {
    registerRolePackage('vendor/zeta', PackageRole::WorkflowFinal);
    registerRolePackage('vendor/alpha', PackageRole::WorkflowFinal);
    registerRolePackage('vendor/early', PackageRole::WorkflowFinal);
    registerRolePackage('vendor/unrelated', PackageRole::DefaultTheme);
    CapellCore::getPackage('vendor/early')->declaredRolePriority = -1;
    CapellCore::getPackage('vendor/zeta')->declaredRolePriority = 5;

    $names = resolve(PackageRoleResolver::class)->packagesWithRole(PackageRole::WorkflowFinal)->keys()->all();

    expect($names)->toBe(['vendor/early', 'vendor/alpha', 'vendor/zeta']);
});

it('names no package when nothing declares the role', function (): void {
    registerRolePackage('vendor/theme', PackageRole::DefaultTheme);

    $resolver = resolve(PackageRoleResolver::class);

    expect($resolver->packageNameWithRole(PackageRole::DeveloperTooling))->toBeNull()
        ->and($resolver->installedPackageNamesWithRole(PackageRole::DeveloperTooling))->toBe([])
        ->and($resolver->hasInstalledPackageWithRole(PackageRole::DeveloperTooling))->toBeFalse()
        ->and($resolver->hasInstalledPackageWithRole(PackageRole::DefaultTheme))->toBeTrue();
});

it('does not infer a role from a package name', function (): void {
    CapellCore::registerPackage('capell-app/theme-foundation', type: PackageTypeEnum::Theme);
    CapellCore::registerPackage('capell-app/agent-bridge');
    CapellCore::registerPackage('capell-app/socials');

    $resolver = resolve(PackageRoleResolver::class);

    foreach (PackageRole::cases() as $role) {
        expect($resolver->packageNameWithRole($role))->toBeNull();
    }
});

it('falls back to a cached catalogue entry for a package that is not installed', function (): void {
    bindCachedCatalogue([
        ['name' => 'capell-app/catalogue-tooling', 'type' => PackageTypeEnum::Plugin->value, 'roles' => ['developer-tooling']],
    ]);

    $resolver = resolve(PackageRoleResolver::class);

    expect($resolver->packageNameWithRole(PackageRole::DeveloperTooling))->toBe('capell-app/catalogue-tooling')
        ->and($resolver->hasInstalledPackageWithRole(PackageRole::DeveloperTooling))->toBeFalse();
});

it('prefers an installed package over a catalogue entry for the same role', function (): void {
    bindCachedCatalogue([
        ['name' => 'vendor/a-catalogue-tooling', 'type' => PackageTypeEnum::Plugin->value, 'roles' => ['developer-tooling']],
    ]);
    registerRolePackage('vendor/z-installed-tooling', PackageRole::DeveloperTooling);

    expect(resolve(PackageRoleResolver::class)->packageNameWithRole(PackageRole::DeveloperTooling))->toBe('vendor/z-installed-tooling');
});

it('lets the installed manifest answer instead of a stale catalogue entry for the same package', function (): void {
    bindCachedCatalogue([
        ['name' => 'vendor/tooling', 'type' => PackageTypeEnum::Plugin->value, 'roles' => ['developer-tooling']],
    ]);
    CapellCore::registerPackage('vendor/tooling');
    CapellCore::forcePackageInstalled('vendor/tooling');

    $known = resolve(PackageRoleResolver::class)->knownPackages();

    expect($known->get('vendor/tooling'))->toBeInstanceOf(PackageData::class)
        ->and($known->get('vendor/tooling')?->isInstalled())->toBeTrue()
        ->and(resolve(PackageRoleResolver::class)->packageNameWithRole(PackageRole::DeveloperTooling))->toBeNull();
});

it('does not let a catalogue entry from another vendor claim a role that installs unprompted', function (): void {
    bindCachedCatalogue([
        ['name' => 'evil/theme', 'type' => 'theme', 'themeKey' => 'default', 'roles' => ['default-theme'], 'rolePriority' => -1],
        ['name' => 'evil/tooling', 'type' => 'plugin', 'roles' => ['developer-tooling']],
        ['name' => 'evil/scaffold', 'type' => 'plugin', 'roles' => ['scaffold-full-profile']],
        defaultThemeCatalogueEntry(),
    ]);

    $resolver = resolve(PackageRoleResolver::class);

    expect($resolver->packageNameWithRole(PackageRole::DefaultTheme))->toBe('capell-app/theme-foundation')
        ->and($resolver->packageNameWithRole(PackageRole::DeveloperTooling))->toBeNull()
        ->and($resolver->packageNameWithRole(PackageRole::ScaffoldFullProfile))->toBeNull();
});

it('still honours a role on a package the user installed from any vendor', function (): void {
    registerRolePackage('vendor/own-theme', PackageRole::DefaultTheme);

    expect(resolve(PackageRoleResolver::class)->packageNameWithRole(PackageRole::DefaultTheme))->toBe('vendor/own-theme');
});

it('ignores a candidate whose name is not a valid Composer package name', function (): void {
    bindCachedCatalogue([
        ['name' => 'capell-app/base"quoted', 'type' => 'plugin', 'roles' => ['scaffold-full-profile']],
        ['name' => 'capell-app/has space', 'type' => 'plugin', 'roles' => ['scaffold-full-profile']],
        ['name' => "capell-app/trailing-newline\n", 'type' => 'plugin', 'roles' => ['scaffold-full-profile']],
    ]);

    expect(resolve(PackageRoleResolver::class)->packageNameWithRole(PackageRole::ScaffoldFullProfile))->toBeNull();
});

it('does not read the catalogue when an installed package already holds the role', function (): void {
    registerRolePackage('vendor/own-theme', PackageRole::DefaultTheme);
    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class extends PluginPackagesFetcher
    {
        #[Override]
        public function getCached(): Collection
        {
            throw new RuntimeException('The catalogue must not be read.');
        }

        #[Override]
        public function fetch(bool $force = false): Collection
        {
            throw new RuntimeException('The catalogue must not be fetched.');
        }
    });

    expect(resolve(PackageRoleResolver::class)->packageNameWithRole(PackageRole::DefaultTheme, refreshCatalogue: true))->toBe('vendor/own-theme');
});

it('fetches the catalogue again on request when the cached copy was cleared', function (): void {
    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class extends PluginPackagesFetcher
    {
        #[Override]
        public function getCached(): Collection
        {
            return collect();
        }

        #[Override]
        public function fetch(bool $force = false): Collection
        {
            return collect([['name' => 'capell-app/catalogue-tooling', 'type' => 'plugin', 'roles' => ['developer-tooling']]]);
        }
    });

    $resolver = resolve(PackageRoleResolver::class);

    expect($resolver->packageNameWithRole(PackageRole::DeveloperTooling))->toBeNull()
        ->and($resolver->packageNameWithRole(PackageRole::DeveloperTooling, refreshCatalogue: true))->toBe('capell-app/catalogue-tooling');
});
