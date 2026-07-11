<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Installer\Http\Middleware\EnsureNotInstalled;
use Capell\Installer\Support\InstallerInstallationState;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    Cache::flush();
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
        DB::purge(is_string($originalDefaultConnection) ? $originalDefaultConnection : null);
    }
});
