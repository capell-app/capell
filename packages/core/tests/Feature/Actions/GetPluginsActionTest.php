<?php

declare(strict_types=1);

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Support\Collection;

// Auto-discovered service providers (e.g. TagsServiceProvider) register packages into CapellCore.
// Clear that state so each test starts with only the packages it explicitly registers.
beforeEach(function (): void {
    CapellCore::clearPackages();
});

function bindPluginPackagesFetcher(Collection $remote, bool $cacheEmpty = false): void
{
    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class($remote, $cacheEmpty) extends PluginPackagesFetcher
    {
        public function __construct(private readonly Collection $remote, private readonly bool $cacheEmpty) {}

        public function fetch(bool $force = false): Collection
        {
            return $this->remote;
        }

        public function getCached(): Collection
        {
            return $this->cacheEmpty ? Collection::make() : $this->remote;
        }
    });
}

/**
 * @return array<int, string>
 */
function nonTrustedPluginNames(?string $filter = null): array
{
    return GetPluginsAction::run($filter)
        ->pluck('name')
        ->reject(fn (string $packageName): bool => TrustedCorePackages::contains($packageName))
        ->values()
        ->all();
}

it('returns merged set for explicit All filter and default (installed + remote not installed)', function (): void {
    CapellCore::registerPackage('installed-alpha');
    CapellCore::forcePackageInstalled('installed-alpha');
    CapellCore::registerPackage('beta-remote');

    $remote = Collection::make([
        ['name' => 'installed-alpha', 'version' => '1.0.0'],
        ['name' => 'beta-remote', 'version' => '0.1.0'],
    ]);

    bindPluginPackagesFetcher($remote);

    $all = nonTrustedPluginNames('All');
    $default = nonTrustedPluginNames();
    expect($default)->toEqual(['installed-alpha', 'beta-remote', 'capell-app/welcome-tour'])
        ->and($all)->toEqual(['installed-alpha', 'beta-remote', 'capell-app/welcome-tour']);
});

it('returns only installed packages when filter is install', function (): void {
    CapellCore::registerPackage('installed-alpha');
    CapellCore::forcePackageInstalled('installed-alpha');
    CapellCore::registerPackage('gamma-remote');

    $remote = Collection::make([
        ['name' => 'installed-alpha'],
        ['name' => 'gamma-remote'],
    ]);

    bindPluginPackagesFetcher($remote);

    $installed = GetPluginsAction::run('install')->pluck('name')->values()->all();
    expect($installed)->toEqual(['installed-alpha']);
});

it('returns only remote uninstalled packages when filter is download', function (): void {
    CapellCore::registerPackage('installed-alpha');
    CapellCore::forcePackageInstalled('installed-alpha');
    CapellCore::registerPackage('delta-remote');

    $remote = Collection::make([
        ['name' => 'installed-alpha'],
        ['name' => 'delta-remote'],
        ['name' => 'epsilon-remote'],
    ]);

    bindPluginPackagesFetcher($remote);

    $notInstalled = nonTrustedPluginNames('download');
    expect($notInstalled)->toEqual(['epsilon-remote', 'capell-app/welcome-tour']);
});

it('installed vs download filters return distinct sets', function (): void {
    CapellCore::registerPackage('installed-alpha');
    CapellCore::forcePackageInstalled('installed-alpha');

    $remote = Collection::make([
        ['name' => 'installed-alpha'],
        ['name' => 'zeta-remote'],
    ]);

    bindPluginPackagesFetcher($remote);

    $installTab = GetPluginsAction::run('install')->pluck('name')->values()->all();
    $notInstalled = nonTrustedPluginNames('download');

    expect($installTab)
        ->toEqual(['installed-alpha'])
        ->and($notInstalled)
        ->toEqual(['zeta-remote', 'capell-app/welcome-tour']);
});

it('fetches remote packages when cache is empty', function (): void {
    CapellCore::registerPackage('alpha');
    $fetched = Collection::make([
        ['name' => 'alpha'],
        ['name' => 'beta-remote'],
    ]);

    bindPluginPackagesFetcher($fetched, true);

    $names = nonTrustedPluginNames();
    expect($names)->toEqual(['alpha', 'beta-remote', 'capell-app/welcome-tour']);
});

it('defaults to merged set for unknown filter casing', function (): void {
    CapellCore::registerPackage('installed-alpha');
    CapellCore::forcePackageInstalled('installed-alpha');
    $remote = Collection::make([
        ['name' => 'installed-alpha'],
        ['name' => 'omega-remote'],
    ]);

    bindPluginPackagesFetcher($remote);

    $unknown = nonTrustedPluginNames('Installed');
    $default = nonTrustedPluginNames();
    expect($unknown)->toEqual(['installed-alpha', 'omega-remote', 'capell-app/welcome-tour'])
        ->and($default)->toEqual(['installed-alpha', 'omega-remote', 'capell-app/welcome-tour']);
});

it('skips remote entries with non-package type or missing name', function (): void {
    CapellCore::registerPackage('foo');
    $remote = Collection::make([
        ['name' => 'foo', 'type' => PackageTypeEnum::Plugin->value],
        ['name' => 'bar-remote', 'type' => PackageTypeEnum::Plugin->value],
        ['name' => 'theme-one', 'type' => PackageTypeEnum::Theme->value],
        ['version' => '1.2.3'], // missing name should be skipped
    ]);

    bindPluginPackagesFetcher($remote);

    $names = nonTrustedPluginNames();
    expect($names)->toEqual(['foo', 'bar-remote', 'theme-one', 'capell-app/welcome-tour']);
});

it('keeps remote theme package metadata for installer theme selection', function (): void {
    config([
        'capell-marketplace.marketplace.web_url' => null,
        'capell.marketplace_web_url' => 'https://capell-test.app',
    ]);

    $remote = Collection::make([
        [
            'name' => 'capell-app/theme-corporate',
            'type' => PackageTypeEnum::Theme->value,
            'themeKey' => 'corporate',
            'extends' => 'capell-app/foundation-theme',
            'image_url' => '/docs/assets/marketplace/theme-corporate.png',
        ],
    ]);

    bindPluginPackagesFetcher($remote);

    $package = GetPluginsAction::run('download')->first();

    expect($package?->type)->toBe(PackageTypeEnum::Theme)
        ->and($package?->getThemeKey())->toBe('corporate')
        ->and($package?->getExtendsPackage())->toBe('capell-app/foundation-theme')
        ->and($package?->getPreviewImageUrl())->toBe('https://capell-test.app/docs/assets/marketplace/theme-corporate.png');
});

it('keeps default core package metadata for installer composer selection', function (): void {
    $remote = Collection::make([
        [
            'name' => 'capell-app/marketplace',
            'type' => PackageTypeEnum::Package->value,
            'defaultSelected' => true,
            'requirements' => ['capell-app/admin', 'capell-app/core'],
        ],
    ]);

    bindPluginPackagesFetcher($remote);

    $package = GetPluginsAction::run('download')->first();

    expect($package?->name)->toBe('capell-app/marketplace')
        ->and($package?->type)->toBe(PackageTypeEnum::Package)
        ->and($package?->isCore())->toBeTrue()
        ->and($package?->defaultSelected)->toBeTrue()
        ->and($package?->getRequirements())->toBe(['capell-app/admin', 'capell-app/core']);
});

it('does not treat string false remote default selection as selected', function (): void {
    $remote = Collection::make([
        [
            'name' => 'capell-app/marketplace',
            'type' => PackageTypeEnum::Package->value,
            'defaultSelected' => 'false',
        ],
    ]);

    bindPluginPackagesFetcher($remote);

    expect(nonTrustedPluginNames('download'))->toBe(['capell-app/welcome-tour']);
});

it('does not treat remote core metadata as installer package selection authority', function (): void {
    $remote = Collection::make([
        [
            'name' => 'vendor/spoofed-core-package',
            'type' => PackageTypeEnum::Package->value,
            'core' => true,
        ],
    ]);

    bindPluginPackagesFetcher($remote);

    expect(nonTrustedPluginNames('download'))->toBe(['capell-app/welcome-tour']);
});

it('ignores remote core metadata on plugin package data', function (): void {
    $remote = Collection::make([
        [
            'name' => 'vendor/spoofed-core-plugin',
            'type' => PackageTypeEnum::Plugin->value,
            'core' => true,
        ],
    ]);

    bindPluginPackagesFetcher($remote);

    $package = GetPluginsAction::run('download')->first();

    expect($package?->name)->toBe('vendor/spoofed-core-plugin')
        ->and($package?->type)->toBe(PackageTypeEnum::Plugin)
        ->and($package?->isCore())->toBeFalse();
});

it('ignores remote core metadata on theme package data', function (): void {
    $remote = Collection::make([
        [
            'name' => 'vendor/spoofed-core-theme',
            'type' => PackageTypeEnum::Theme->value,
            'core' => true,
            'themeKey' => 'spoofed-theme',
        ],
    ]);

    bindPluginPackagesFetcher($remote);

    $package = GetPluginsAction::run('download')->first();

    expect($package?->name)->toBe('vendor/spoofed-core-theme')
        ->and($package?->type)->toBe(PackageTypeEnum::Theme)
        ->and($package?->isCore())->toBeFalse()
        ->and($package?->getThemeKey())->toBe('spoofed-theme');
});

it('deduplicates installed package appearing in remote list', function (): void {
    CapellCore::registerPackage('dup-alpha');
    CapellCore::forcePackageInstalled('dup-alpha');

    $remote = Collection::make([
        ['name' => 'dup-alpha'],
        ['name' => 'new-remote'],
    ]);

    bindPluginPackagesFetcher($remote);

    $names = nonTrustedPluginNames();
    expect($names)->toEqual(['dup-alpha', 'new-remote', 'capell-app/welcome-tour']);
});

it('returns the default welcome tour selection when no packages are registered and the remote cache is empty', function (): void {
    bindPluginPackagesFetcher(Collection::make(), true);
    $result = nonTrustedPluginNames();

    expect($result)->toBe(['capell-app/welcome-tour']);
});
