<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Events\CapellInstallationCompleted;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Capell\Installer\Http\Middleware\EnsureNotInstalled;
use Capell\Installer\Support\InstallerInstallationState;
use Capell\Installer\Support\InstallerRuntimeMemo;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    Cache::flush();
    resolve(CapellCacheManager::class)->flushLocalCache();
    config(['capell-installer.installation_state_cache.host' => 'installer-state-test']);
    InstallerInstallationState::forget();

    app()->instance(InstalledExtensionRepository::class, new readonly class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return true;
        }
    });
});

it('uses one query for a mid-install state and none after either cache tier is warm', function (): void {
    Site::query()->delete();

    expect(installerStateQueryCount(fn (): bool => InstallerInstallationState::capellIsInstalled()))
        ->toBe(['result' => false, 'queries' => 1]);

    expect(installerStateQueryCount(fn (): bool => InstallerInstallationState::capellIsInstalled()))
        ->toBe(['result' => false, 'queries' => 0]);

    resolve(InstallerRuntimeMemo::class)->flush();
    resolve(CapellCacheManager::class)->flushLocalCache();

    expect(installerStateQueryCount(fn (): bool => InstallerInstallationState::capellIsInstalled()))
        ->toBe(['result' => false, 'queries' => 0]);
});

it('uses one query for an installed state and none after either cache tier is warm', function (): void {
    Site::factory()->createOne();

    expect(installerStateQueryCount(fn (): bool => InstallerInstallationState::capellIsInstalled()))
        ->toBe(['result' => true, 'queries' => 1]);

    expect(installerStateQueryCount(fn (): bool => InstallerInstallationState::capellIsInstalled()))
        ->toBe(['result' => true, 'queries' => 0]);

    resolve(InstallerRuntimeMemo::class)->flush();
    resolve(CapellCacheManager::class)->flushLocalCache();

    expect(installerStateQueryCount(fn (): bool => InstallerInstallationState::capellIsInstalled()))
        ->toBe(['result' => true, 'queries' => 0]);
});

it('treats an unavailable fresh database as not installed without caching the indeterminate probe', function (): void {
    $originalDefaultConnection = config('database.default');

    config([
        'database.default' => 'installer_state_missing_connection',
        'database.connections.installer_state_missing_connection' => [
            'driver' => 'sqlite',
            'database' => '/sys/capell-missing/installer-state.sqlite',
            'prefix' => '',
        ],
    ]);

    DB::purge('installer_state_missing_connection');

    try {
        expect(installerStateQueryCount(fn (): bool => InstallerInstallationState::capellIsInstalled()))
            ->toBe(['result' => false, 'queries' => 0]);
    } finally {
        config(['database.default' => $originalDefaultConnection]);
        DB::purge('installer_state_missing_connection');
    }

    Site::factory()->createOne();

    expect(InstallerInstallationState::capellIsInstalled())->toBeTrue();
});

it('treats a missing fresh schema as not installed without caching the indeterminate probe', function (): void {
    $originalDefaultConnection = config('database.default');

    config([
        'database.default' => 'installer_state_fresh_database',
        'database.connections.installer_state_fresh_database' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
    ]);

    DB::purge('installer_state_fresh_database');

    try {
        expect(installerStateQueryCount(fn (): bool => InstallerInstallationState::capellIsInstalled()))
            ->toBe(['result' => false, 'queries' => 0]);
    } finally {
        config(['database.default' => $originalDefaultConnection]);
        DB::purge('installer_state_fresh_database');
    }

    Site::factory()->createOne();

    expect(InstallerInstallationState::capellIsInstalled())->toBeTrue();
});

it('separates persistent installation state by configured host', function (): void {
    Site::query()->delete();
    config(['capell-installer.installation_state_cache.host' => 'host-one']);

    expect(InstallerInstallationState::capellIsInstalled())->toBeFalse();

    Site::factory()->createOne();
    config(['capell-installer.installation_state_cache.host' => 'host-two']);

    expect(InstallerInstallationState::capellIsInstalled())->toBeTrue();
});

it('separates persistent installation state by configured database', function (): void {
    Site::query()->delete();
    $connection = (string) config('database.default');
    $originalDatabase = config(sprintf('database.connections.%s.database', $connection));

    expect(InstallerInstallationState::capellIsInstalled())->toBeFalse();

    Site::factory()->createOne();
    config([sprintf('database.connections.%s.database', $connection) => $originalDatabase . '_other']);

    try {
        expect(InstallerInstallationState::capellIsInstalled())->toBeTrue();
    } finally {
        config([sprintf('database.connections.%s.database', $connection) => $originalDatabase]);
    }
});

it('invalidates cached installation state when installation or migrations complete', function (): void {
    Site::query()->delete();

    expect(InstallerInstallationState::capellIsInstalled())->toBeFalse();

    Site::factory()->createOne();

    expect(InstallerInstallationState::capellIsInstalled())->toBeFalse();

    event(new CapellInstallationCompleted);

    expect(InstallerInstallationState::capellIsInstalled())->toBeTrue();

    Site::query()->delete();

    expect(InstallerInstallationState::capellIsInstalled())->toBeTrue();

    event(new MigrationsEnded('up'));

    expect(InstallerInstallationState::capellIsInstalled())->toBeFalse();
});

it('blocks non-show installer routes once Capell is already installed', function (): void {
    config(['capell-installer.allow_reinstall' => false]);
    Cache::forget('capell.install.lock');

    CapellCore::forcePackageInstalled(AdminServiceProvider::$packageName);
    Site::factory()->createOne();

    expect(InstallerInstallationState::capellIsInstalled())->toBeTrue();

    $request = Request::create(route('capell-installer.progress', ['installId' => 'installed-site']), Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->setRouteResolver(static fn (): Route => new Route('GET', '/install/progress/{installId}', fn (): string => 'ok')->name('capell-installer.progress'));

    expect(fn (): Response => (new EnsureNotInstalled)->handle($request, static fn (): Response => new Response('next')))
        ->toThrow(NotFoundHttpException::class);
});

it('allows installer access when active install cache lookups fail closed', function (): void {
    Cache::shouldReceive('get')
        ->with('capell.install.lock')
        ->andThrow(new RuntimeException('cache unavailable'));

    $response = (new EnsureNotInstalled)->handle(
        Request::create('/install', Symfony\Component\HttpFoundation\Request::METHOD_GET),
        static fn (): Response => new Response('next'),
    );

    expect($response->getContent())->toBe('next');
});

it('allows installer access when database cache store validation throws before migrations exist', function (): void {
    $originalDefaultConnection = config('database.default');

    config([
        'database.default' => 'ensure_not_installed_missing_connection',
        'database.connections.ensure_not_installed_missing_connection' => [
            'driver' => 'sqlite',
            'database' => '/sys/capell-missing/cache.sqlite',
            'prefix' => '',
        ],
        'cache.default' => 'database',
        'cache.stores.database.table' => 'cache',
    ]);

    DB::purge('ensure_not_installed_missing_connection');

    try {
        $response = (new EnsureNotInstalled)->handle(
            Request::create('/install', Symfony\Component\HttpFoundation\Request::METHOD_GET),
            static fn (): Response => new Response('next'),
        );

        expect($response->getContent())->toBe('next');
    } finally {
        config(['database.default' => $originalDefaultConnection]);
        DB::purge('ensure_not_installed_missing_connection');
        // See above: purging the in-memory default connection would drop the
        // migrated schema, so it is deliberately left connected.
    }
});

/** @return array{result: bool, queries: int} */
function installerStateQueryCount(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $result = $callback();
        $queries = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    return ['result' => $result, 'queries' => $queries];
}
