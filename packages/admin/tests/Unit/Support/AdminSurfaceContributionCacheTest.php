<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Support\AdminSurfaceContributionCache;
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    resolve(AdminSurfaceContributionCache::class)->clear();
    resolve(AdminSurfaceContributionRegistry::class)->clear();
});

afterEach(function (): void {
    resolve(AdminSurfaceContributionCache::class)->clear();
    resolve(AdminSurfaceContributionRegistry::class)->clear();
});

it('writes the registry snapshot in the existing PHP array format', function (): void {
    $registry = resolve(AdminSurfaceContributionRegistry::class);
    $cache = resolve(AdminSurfaceContributionCache::class);
    $registry->register(AdminSurfaceContributionData::configurator(
        DefaultPageConfigurator::class,
        'page',
        'package-page',
    ));

    $cache->cache();

    $cachedContributions = require $cache->path();

    expect($cache->path())->toEndWith('bootstrap/cache/capell-admin-configurators.php')
        ->and(resolve(Filesystem::class)->exists($cache->path()))->toBeTrue()
        ->and($cachedContributions)->toBe([
            'configurator' => [
                'configurator:page:package-page' => [
                    'type' => 'configurator',
                    'class' => DefaultPageConfigurator::class,
                    'key' => 'configurator:page:package-page',
                    'group' => 'page',
                    'name' => 'package-page',
                    'tag' => null,
                ],
            ],
        ]);
});

it('clears then restores keyed contribution groups in their cached order', function (): void {
    $registry = resolve(AdminSurfaceContributionRegistry::class);
    $cache = resolve(AdminSurfaceContributionCache::class);
    $registry->register(AdminSurfaceContributionData::configurator(
        DefaultPageConfigurator::class,
        'page',
        'first',
    ));
    $registry->register(AdminSurfaceContributionData::configurator(
        self::class,
        'page',
        'second',
    ));
    $cache->cache();

    $registry->clear();
    $registry->register(AdminSurfaceContributionData::configurator(
        DefaultPageConfigurator::class,
        'page',
        'stale',
    ));

    $cache->restore();

    expect(array_keys($registry->all()['configurator']))->toBe([
        'configurator:page:first',
        'configurator:page:second',
    ])
        ->and($registry->all()['configurator'])->not->toHaveKey('configurator:page:stale');
});

it('does not change the registry when no cache exists', function (): void {
    $registry = resolve(AdminSurfaceContributionRegistry::class);
    $cache = resolve(AdminSurfaceContributionCache::class);
    $registry->register(AdminSurfaceContributionData::page(DefaultPageConfigurator::class));

    $cache->restore();

    expect($registry->all())->toHaveKey('page');
});
