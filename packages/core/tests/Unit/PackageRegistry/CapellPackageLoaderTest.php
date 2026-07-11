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
use Mockery\MockInterface;

it('always includes metadata and install providers for discovered packages', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/blog' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'capell-app/blog',
            surfaces: ['admin', 'frontend'],
            providers: [
                'metadata' => [AuthServiceProvider::class],
                'install' => [CacheServiceProvider::class],
                'runtime' => [FilesystemServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')
        ->once()
        ->with('capell-app/blog')
        ->andReturnFalse();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Admin);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $loader = new CapellPackageLoader(
        app: $app,
        registry: $registry,
        contextResolver: $resolver,
    );

    $providers = $loader->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->toContain(CacheServiceProvider::class)
        ->and($providers)->not->toContain(FilesystemServiceProvider::class);
});

it('loads runtime and admin providers for enabled packages in admin context', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/blog' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'capell-app/blog',
            surfaces: ['admin', 'frontend'],
            providers: [
                'runtime' => [AuthServiceProvider::class],
                'admin' => [CacheServiceProvider::class],
                'frontend' => [FilesystemServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')
        ->once()
        ->with('capell-app/blog')
        ->andReturnTrue();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Admin);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $loader = new CapellPackageLoader($app, $registry, $resolver);
    $providers = $loader->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->toContain(CacheServiceProvider::class)
        ->and($providers)->not->toContain(FilesystemServiceProvider::class);
});

it('loads admin providers for enabled packages in console context', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/blog' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'capell-app/blog',
            surfaces: ['admin', 'console'],
            providers: [
                'runtime' => [AuthServiceProvider::class],
                'admin' => [CacheServiceProvider::class],
                'frontend' => [FilesystemServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')
        ->once()
        ->with('capell-app/blog')
        ->andReturnTrue();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Console);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $loader = new CapellPackageLoader($app, $registry, $resolver);
    $providers = $loader->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->toContain(CacheServiceProvider::class)
        ->and($providers)->not->toContain(FilesystemServiceProvider::class);
});

it('loads frontend providers only in frontend context', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/blog' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'capell-app/blog',
            surfaces: ['frontend'],
            providers: [
                'runtime' => [AuthServiceProvider::class],
                'admin' => [CacheServiceProvider::class],
                'frontend' => [FilesystemServiceProvider::class],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')
        ->once()
        ->with('capell-app/blog')
        ->andReturnTrue();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Frontend);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $loader = new CapellPackageLoader($app, $registry, $resolver);
    $providers = $loader->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->toContain(FilesystemServiceProvider::class)
        ->and($providers)->not->toContain(CacheServiceProvider::class);
});

it('loads runtime providers for trusted core packages without checking lifecycle state', function (): void {
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

    $loader = new CapellPackageLoader($app, $registry, $resolver);
    $providers = $loader->collectProviders();

    expect($providers)->toContain(AuthServiceProvider::class)
        ->and($providers)->toContain(CacheServiceProvider::class);
});

it('skips providers for non-existent classes gracefully', function (): void {
    $registry = new CapellPackageRegistry;
    $registry->fill([
        'capell-app/ghost' => CapellManifestData::fromArray(capellManifestV3Array(
            name: 'capell-app/ghost',
            surfaces: ['admin'],
            providers: [
                'admin' => ['Capell\\Ghost\\Providers\\NonExistentProvider'],
            ],
        )),
    ]);

    CapellCore::shouldReceive('isPackageEnabled')
        ->once()
        ->with('capell-app/ghost')
        ->andReturnTrue();

    /** @var RuntimeContextResolver&MockInterface $resolver */
    $resolver = Mockery::mock(RuntimeContextResolver::class);
    $resolver->allows('resolve')->andReturn(RuntimeContextEnum::Admin);

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);

    $loader = new CapellPackageLoader($app, $registry, $resolver);

    expect(fn () => $loader->loadProviders())->not->toThrow(Throwable::class);
});
