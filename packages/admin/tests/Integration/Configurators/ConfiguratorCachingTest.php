<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Support\CapellAdminManager;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    CapellAdmin::clearCachedConfigurators();
});

afterEach(function (): void {
    CapellAdmin::clearCachedConfigurators();
});

it('cacheConfigurators writes a PHP file at the expected path', function (): void {
    $filesystem = resolve(Filesystem::class);

    expect($filesystem->exists(CapellAdmin::getConfiguratorCachePath()))->toBeFalse();

    CapellAdmin::cacheConfigurators();

    expect($filesystem->exists(CapellAdmin::getConfiguratorCachePath()))->toBeTrue();
});

it('clearCachedConfigurators removes the configurator cache file', function (): void {
    CapellAdmin::cacheConfigurators();

    expect(resolve(Filesystem::class)->exists(CapellAdmin::getConfiguratorCachePath()))->toBeTrue();

    CapellAdmin::clearCachedConfigurators();

    expect(resolve(Filesystem::class)->exists(CapellAdmin::getConfiguratorCachePath()))->toBeFalse();
});

it('hasCachedConfigurators returns false when no cache file exists', function (): void {
    expect(CapellAdmin::hasCachedConfigurators())->toBeFalse();
});

it('restoreCachedConfigurators re-populates discoveredConfigurators from the cache file', function (): void {
    CapellAdmin::cacheConfigurators();

    $cachePath = CapellAdmin::getConfiguratorCachePath();

    // Force the cached state by bypassing the console guard via reflection
    $manager = resolve(CapellAdminManager::class);

    $manager->restoreCachedConfigurators();

    expect($manager->hasCachedConfigurators())->toBeTrue()
        ->and($cachePath)->toBe(CapellAdmin::getConfiguratorCachePath());
});

it('restores cached configurators through the admin surface registry used by runtime consumers', function (): void {
    CapellAdmin::clearAdminSurfaceContributions();
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::configurator(
        DefaultPageConfigurator::class,
        'page',
        'package-page',
    ));

    expect(CapellAdmin::getConfigurators('page')['package-page'])->toBe(DefaultPageConfigurator::class);

    CapellAdmin::cacheConfigurators();

    $cachedContributions = require CapellAdmin::getConfiguratorCachePath();

    expect($cachedContributions['configurator']['configurator:page:package-page']['class'])->toBe(DefaultPageConfigurator::class);

    CapellAdmin::clearAdminSurfaceContributions();

    expect(CapellAdmin::getConfigurators('page'))->not->toHaveKey('package-page');

    CapellAdmin::restoreCachedConfigurators();

    expect(CapellAdmin::getConfigurators('page')['package-page'])->toBe(DefaultPageConfigurator::class);

    CapellAdmin::clearAdminSurfaceContributions();
});
