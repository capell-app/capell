<?php

declare(strict_types=1);

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Octane\Resettable;
use Capell\Frontend\Contracts\FrontendComponentRegistryInterface;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Cache\FragmentCacheDirective;
use Capell\Frontend\Support\View\ThemeViewRegistrar;

it('frontend package has frontend scope', function (): void {
    $package = CapellCore::getPackage(FrontendServiceProvider::$packageName);

    expect($package->hasFrontendScope())->toBeTrue();
    expect($package->getScopes())->toContain(PackageScopeEnum::Frontend);
});

it('registers core-safe extension services', function (): void {
    expect(app()->bound('capell.frontend.context'))->toBeTrue()
        ->and(app()->bound('capell.frontend.retrieved-model-store'))->toBeTrue()
        ->and(app()->bound('capell.frontend.layout-container-width-resolver'))->toBeTrue()
        ->and(resolve('capell.frontend.layout-container-width-resolver'))->toBeCallable();
});

it('tags frontend request state services for octane resets', function (): void {
    $resettableServices = collect(app()->tagged(Resettable::TAG))
        ->map(fn (object $service): string => $service::class)
        ->all();

    expect($resettableServices)->toContain(
        CacheInvalidationRegistry::class,
        FragmentCacheDirective::class,
        ThemeViewRegistrar::class,
    );
});

it('registers frontend css for generated tailwind assets', function (): void {
    $tailwindImports = CapellCore::getVendorAssetsForType(VendorAssetEnum::TailwindImport);

    expect($tailwindImports->pluck('value')->all())
        ->toContain('resources/css/capell-frontend.css');
});

it('registers the shared frontend alpine runtime as a package-owned build asset', function (): void {
    $buildAssets = CapellCore::getVendorAssetsForType(VendorAssetEnum::BuildAsset);

    expect($buildAssets->map(fn (VendorAssetData $asset): array => [
        'path' => $asset->path(),
        'file' => $asset->file(),
        'package' => $asset->packageName,
    ])->all())->toContain([
        'path' => 'vendor/capell-frontend',
        'file' => 'resources/js/capell-frontend.js',
        'package' => FrontendServiceProvider::$packageName,
    ]);
});

it('resolves core asset component keys for frontend rendering', function (): void {
    $registry = resolve(FrontendComponentRegistryInterface::class);

    expect($registry->resolve(AssetComponentEnum::Card->value))->toBe('capell::asset.index')
        ->and($registry->resolve(AssetComponentEnum::Media->value))->toBe('capell::media.asset')
        ->and($registry->resolve(AssetComponentEnum::Page->value))->toBe('capell::page.asset')
        ->and($registry->resolve(AssetComponentEnum::Tile->value))->toBe('capell::asset.tile');
});
