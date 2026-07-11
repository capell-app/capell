<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Cache\AdminCacheCleaner;
use Capell\Admin\Tests\Fixtures\Autoload\ClearCacheCommandTestCleaner;
use Capell\Core\Facades\CapellCore;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\View;

beforeEach(function (): void {
    CapellCore::swap(CapellCore::partialMock());
    View::swap(View::partialMock());
});

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Mockery::close();
});

it('clears all caches successfully', function (): void {
    CapellCore::spy();
    CapellCore::shouldReceive('flushCache')->once()->andReturnNull();

    View::spy();
    View::shouldReceive('flushFinderCache')->once()->andReturnNull();

    artisanCommand('capell:admin-clear-cache')
        ->assertExitCode(0);
});

it('removes the local app theme definition cache file', function (): void {
    CapellCore::spy();
    CapellCore::shouldReceive('flushCache')->once()->andReturnNull();

    View::spy();
    View::shouldReceive('flushFinderCache')->once()->andReturnNull();

    $cachePath = resolve(LocalAppThemeDefinitionRepository::class)->cachePath();
    file_put_contents($cachePath, '<?php return [];');

    artisanCommand('capell:admin-clear-cache')
        ->assertExitCode(0);

    expect(file_exists($cachePath))->toBeFalse();
});

it('executes cache clearing operations in correct order', function (): void {
    ClearCacheCommandTestCleaner::$executionOrder = [];

    CapellCore::swap(CapellCore::partialMock());
    CapellCore::shouldReceive('flushCache')
        ->once()
        ->andReturnUsing(function (): void {
            ClearCacheCommandTestCleaner::$executionOrder[] = 'core-cache';
        });

    View::shouldReceive('flushFinderCache')
        ->once()
        ->andReturnUsing(function (): void {
            ClearCacheCommandTestCleaner::$executionOrder[] = 'view-cache';
        });

    app()->tag([ClearCacheCommandTestCleaner::class], AdminCacheCleaner::TAG);

    artisanCommand('capell:admin-clear-cache')
        ->assertExitCode(0);

    expect(ClearCacheCommandTestCleaner::$executionOrder)->toBe(['core-cache', 'view-cache', 'tagged-cache']);
});
