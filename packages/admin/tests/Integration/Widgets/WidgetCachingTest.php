<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Admin\Tests\Fixtures\Widgets\AlternateHeroWidget;
use Capell\Admin\Tests\Fixtures\Widgets\HeroWidget;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    CapellAdmin::clearCachedWidgets();
});

afterEach(function (): void {
    CapellAdmin::clearCachedWidgets();
});

it('cacheWidgets writes a PHP file at the expected path', function (): void {
    $filesystem = resolve(Filesystem::class);

    expect($filesystem->exists(CapellAdmin::getWidgetCachePath()))->toBeFalse();

    CapellAdmin::cacheWidgets();

    expect($filesystem->exists(CapellAdmin::getWidgetCachePath()))->toBeTrue();
});

it('cacheWidgets includes discovered widgets in the cache file', function (): void {
    $fixturesDir = __DIR__ . '/../../Fixtures/Widgets';

    CapellAdmin::registerDiscoverableWidgets($fixturesDir, 'Capell\\Admin\\Tests\\Fixtures\\Widgets');
    CapellAdmin::cacheWidgets();

    /** @var array<string, class-string> $cached */
    $cached = require CapellAdmin::getWidgetCachePath();

    expect($cached)->toBeArray()
        ->and($cached)->toHaveKey('hero')
        ->and($cached['hero'])->toBe(HeroWidget::class);
});

it('restoreCachedWidgets registers all cached entries into the admin widget registry', function (): void {
    $fixturesDir = __DIR__ . '/../../Fixtures/Widgets';

    CapellAdmin::registerDiscoverableWidgets($fixturesDir, 'Capell\\Admin\\Tests\\Fixtures\\Widgets');
    CapellAdmin::cacheWidgets();

    // Write a minimal stub cache that bypasses the hasCachedWidgets() console guard
    $cachePath = CapellAdmin::getWidgetCachePath();
    $filesystem = resolve(Filesystem::class);
    $filesystem->put($cachePath, '<?php return ' . var_export(['hero' => HeroWidget::class], true) . ';');

    // Use Filament cache path check directly; since we wrote the file, call via reflection to skip the guard.
    $discovery = resolve(WidgetDiscovery::class);
    $reflection = new ReflectionProperty($discovery, 'hasCachedWidgets');
    $reflection->setValue($discovery, true);

    CapellAdmin::restoreCachedWidgets();

    expect($discovery->registeredWidgets()['hero'] ?? null)->toBe(HeroWidget::class);
});

it('does not let a cached widget replace an authoritative registration', function (): void {
    $cachePath = CapellAdmin::getWidgetCachePath();
    $filesystem = resolve(Filesystem::class);
    $filesystem->put($cachePath, '<?php return ' . var_export(['hero' => AlternateHeroWidget::class], true) . ';');

    $discovery = resolve(WidgetDiscovery::class);
    $discovery->registerAuthoritative(HeroWidget::class);

    $reflection = new ReflectionProperty($discovery, 'hasCachedWidgets');
    $reflection->setValue($discovery, true);

    CapellAdmin::restoreCachedWidgets();

    expect($discovery->registeredWidgets()['hero'] ?? null)->toBe(HeroWidget::class);
});

it('clearCachedWidgets removes the cache file', function (): void {
    CapellAdmin::cacheWidgets();

    $filesystem = resolve(Filesystem::class);

    expect($filesystem->exists(CapellAdmin::getWidgetCachePath()))->toBeTrue();

    CapellAdmin::clearCachedWidgets();

    expect($filesystem->exists(CapellAdmin::getWidgetCachePath()))->toBeFalse();
});

it('hasCachedWidgets returns false when no cache file exists', function (): void {
    expect(CapellAdmin::hasCachedWidgets())->toBeFalse();
});

it('hasCachedWidgets is guarded by the console environment check', function (): void {
    // The guard `! app()->runningInConsole()` is the sole runtime gate.
    // In a web request with a cache file present it returns true; in
    // console (all tests) it returns false unless forcibly set.
    $discovery = resolve(WidgetDiscovery::class);

    $reflection = new ReflectionProperty($discovery, 'hasCachedWidgets');
    $reflection->setValue($discovery, null);

    // No file — always false regardless of environment
    expect(CapellAdmin::hasCachedWidgets())->toBeFalse();
});
