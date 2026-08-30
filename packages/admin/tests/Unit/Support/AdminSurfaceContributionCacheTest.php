<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Pages\SitemapPage;
use Capell\Admin\Support\AdminSurfaceContributionCache;
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Capell\Admin\Tests\Fixtures\Configurators\TestTableConfigurator;
use Capell\Core\Support\Extensions\ExtensionPosition;
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
            'schema_version' => 2,
            'configurator' => [
                'configurator:page:package-page' => [
                    'type' => 'configurator',
                    'class' => DefaultPageConfigurator::class,
                    'key' => 'configurator:page:package-page',
                    'group' => 'page',
                    'name' => 'package-page',
                    'tag' => null,
                    'owner' => 'capell-app/admin',
                    'position' => null,
                    'source' => AdminSurfaceContributionData::class,
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
        TestTableConfigurator::class,
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

it('preserves external contribution provenance and ordering metadata after restore', function (): void {
    $registry = resolve(AdminSurfaceContributionRegistry::class);
    $cache = resolve(AdminSurfaceContributionCache::class);
    $anchor = new AdminSurfaceContributionData(
        type: AdminSurfaceContributionType::Page,
        class: SitemapPage::class,
        key: 'external-anchor',
        owner: 'vendor/anchor-admin',
        position: ExtensionPosition::priority(20),
        source: 'Vendor\\Anchor\\AdminServiceProvider',
    );
    $external = new AdminSurfaceContributionData(
        type: AdminSurfaceContributionType::Page,
        class: SettingsPage::class,
        key: 'external-before-anchor',
        owner: 'vendor/external-admin',
        position: ExtensionPosition::before('external-anchor'),
        source: 'Vendor\\External\\AdminServiceProvider',
    );

    $registry->register($anchor);
    $registry->register($external);

    $liveOrder = $registry->pages();

    $cache->cache();
    $registry->clear();
    $cache->restore();

    $restored = $registry->all()['page']['external-before-anchor'];

    expect($restored->owner)->toBe($external->owner)
        ->and($restored->source)->toBe($external->source)
        ->and($restored->position?->kind)->toBe('before')
        ->and($restored->position?->anchor)->toBe('external-anchor')
        ->and($registry->pages())->toBe($liveOrder);
});

it('restores the legacy unversioned cache payload with legacy metadata defaults', function (): void {
    $registry = resolve(AdminSurfaceContributionRegistry::class);
    $cache = resolve(AdminSurfaceContributionCache::class);
    $filesystem = resolve(Filesystem::class);

    $filesystem->put($cache->path(), '<?php return ' . var_export([
        'configurator' => [
            'configurator:page:legacy' => [
                'type' => 'configurator',
                'class' => DefaultPageConfigurator::class,
                'key' => 'configurator:page:legacy',
                'group' => 'page',
                'name' => 'legacy',
                'tag' => null,
            ],
        ],
    ], true) . ';' . PHP_EOL);

    $cache->restore();
    $restored = $registry->all()['configurator']['configurator:page:legacy'];

    expect($restored->owner)->toBe('capell-app/admin')
        ->and($restored->position)->toBeNull()
        ->and($restored->source)->toBe(AdminSurfaceContributionData::class);
});

it('does not change the registry when no cache exists', function (): void {
    $registry = resolve(AdminSurfaceContributionRegistry::class);
    $cache = resolve(AdminSurfaceContributionCache::class);
    $registry->register(AdminSurfaceContributionData::page(DefaultPageConfigurator::class));

    $cache->restore();

    expect($registry->all())->toHaveKey('page');
});
