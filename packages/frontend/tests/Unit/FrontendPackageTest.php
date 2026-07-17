<?php

declare(strict_types=1);

use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Octane\Resettable;
use Capell\Frontend\Contracts\Fragments\PublicFragmentReferenceCodec;
use Capell\Frontend\Contracts\FrontendComponentRegistryInterface;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Cache\FragmentCacheDirective;
use Capell\Frontend\Support\Fragments\PublicFragmentUrlResolverRegistry;
use Capell\Frontend\Support\View\ThemeViewRegistrar;

it('frontend package has frontend scope', function (): void {
    $package = CapellCore::getPackage(FrontendServiceProvider::$packageName);

    expect($package->hasFrontendScope())->toBeTrue();
    expect($package->getScopes())->toContain(PackageScopeEnum::Frontend);
});

it('registers core-safe extension services', function (): void {
    expect(app()->bound(FrontendContextReader::class))->toBeTrue()
        ->and(app()->bound('capell.frontend.retrieved-model-store'))->toBeTrue()
        ->and(app()->bound('capell.frontend.layout-container-width-resolver'))->toBeTrue()
        ->and(resolve('capell.frontend.layout-container-width-resolver'))->toBeCallable();
});

it('registers the owner-aware public fragment services', function (): void {
    expect(app()->bound(PublicFragmentReferenceCodec::class))->toBeTrue()
        ->and(app()->bound(PublicFragmentUrlResolverRegistry::class))->toBeTrue();
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

it('resolves core asset component keys for frontend rendering', function (): void {
    $registry = resolve(FrontendComponentRegistryInterface::class);

    expect($registry->resolve(AssetComponentEnum::Card->value))->toBe('capell::asset.index')
        ->and($registry->resolve(AssetComponentEnum::Media->value))->toBe('capell::media.asset')
        ->and($registry->resolve(AssetComponentEnum::Page->value))->toBe('capell::page.asset')
        ->and($registry->resolve(AssetComponentEnum::Tile->value))->toBe('capell::asset.tile');
});
