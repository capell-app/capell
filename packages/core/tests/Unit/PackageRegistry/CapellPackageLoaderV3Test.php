<?php

declare(strict_types=1);

use Capell\Core\Enums\RuntimeContextEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\PackageRegistry\RuntimeContextResolver;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

it('does not load runtime providers for disabled non-core manifest v3 packages', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/disabled-package' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/disabled-package',
            surfaces: ['admin'],
            providers: [
                'metadata' => [AuthServiceProvider::class],
                'install' => [CacheServiceProvider::class],
                'runtime' => [FilesystemServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')
        ->once()
        ->with('vendor/disabled-package')
        ->andReturnFalse();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Admin);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $providers = new CapellPackageLoader($app, $registry, $resolver)->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->toContain(CacheServiceProvider::class)
        ->and($providers)->not->toContain(FilesystemServiceProvider::class);
});

it('loads runtime providers for trusted core manifest v3 packages without runtime gate checks', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/core' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'capell-app/core',
            surfaces: ['admin'],
            providers: [
                'runtime' => [AuthServiceProvider::class],
                'admin' => [CacheServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->never();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Admin);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $providers = new CapellPackageLoader($app, $registry, $resolver)->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->toContain(CacheServiceProvider::class);
});

it('does not load non-core install runtime or frontend providers in auth context', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/auth-skipped-package' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/auth-skipped-package',
            surfaces: ['frontend'],
            providers: [
                'metadata' => [AuthServiceProvider::class],
                'install' => [CacheServiceProvider::class],
                'runtime' => [FilesystemServiceProvider::class],
                'frontend' => [HashServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->never();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Auth);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $providers = new CapellPackageLoader($app, $registry, $resolver)->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->not->toContain(CacheServiceProvider::class)
        ->and($providers)->not->toContain(FilesystemServiceProvider::class)
        ->and($providers)->not->toContain(HashServiceProvider::class);
});

it('loads enabled non-core auth providers in auth context', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/auth-package' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/auth-package',
            surfaces: ['admin'],
            providers: [
                'metadata' => [AuthServiceProvider::class],
                'install' => [CacheServiceProvider::class],
                'runtime' => [FilesystemServiceProvider::class],
                'auth' => [HashServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')
        ->once()
        ->with('vendor/auth-package')
        ->andReturnTrue();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Auth);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $providers = new CapellPackageLoader($app, $registry, $resolver)->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->toContain(HashServiceProvider::class)
        ->and($providers)->not->toContain(CacheServiceProvider::class)
        ->and($providers)->not->toContain(FilesystemServiceProvider::class);
});

it('does not load disabled non-core auth providers in auth context', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/disabled-auth-package' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/disabled-auth-package',
            surfaces: ['admin'],
            providers: [
                'metadata' => [AuthServiceProvider::class],
                'auth' => [HashServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')
        ->once()
        ->with('vendor/disabled-auth-package')
        ->andReturnFalse();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Auth);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $providers = new CapellPackageLoader($app, $registry, $resolver)->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->not->toContain(HashServiceProvider::class);
});

it('does not run install-state queries for auth requests when packages do not declare auth providers', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'vendor/auth-quiet-one' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/auth-quiet-one',
            surfaces: ['admin'],
            providers: [
                'metadata' => [AuthServiceProvider::class],
                'install' => [CacheServiceProvider::class],
                'runtime' => [FilesystemServiceProvider::class],
            ],
        )),
        'vendor/auth-quiet-two' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'vendor/auth-quiet-two',
            surfaces: ['frontend'],
            providers: [
                'metadata' => [AuthServiceProvider::class],
                'install' => [CacheServiceProvider::class],
                'runtime' => [FilesystemServiceProvider::class],
            ],
        )),
    ]);

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Auth);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $providers = new CapellPackageLoader($app, $registry, $resolver)->collectProviders();
        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    $extensionLedgerQueries = $queries->filter(function (array $query): bool {
        $querySql = $query['query'];

        return str_contains($querySql, 'capell_extensions');
    });

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->not->toContain(CacheServiceProvider::class)
        ->and($providers)->not->toContain(FilesystemServiceProvider::class)
        ->and($extensionLedgerQueries)->toHaveCount(0);
});

it('loads trusted core runtime providers in auth context without frontend providers', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/core' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'capell-app/core',
            surfaces: ['frontend'],
            providers: [
                'runtime' => [AuthServiceProvider::class],
                'frontend' => [CacheServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')->never();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Auth);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $providers = new CapellPackageLoader($app, $registry, $resolver)->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->not->toContain(CacheServiceProvider::class);
});
