<?php

declare(strict_types=1);

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Assets\VendorAssetConditionRegistry;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Assets\VendorBuildAssetContributor;

beforeEach(function (): void {
    resetVendorBuildAssetContributorAssets();
    config()->set('capell-frontend.asset_build_tool', 'static');
});

afterEach(function (): void {
    resetVendorBuildAssetContributorAssets();
});

function resetVendorBuildAssetContributorAssets(): void
{
    $manager = CapellCore::getFacadeRoot();
    $property = new ReflectionProperty($manager, 'vendorAssets');
    $property->setValue($manager, []);
}

it('includes vendor javascript assets when the frontend runtime uses inertia', function (): void {
    CapellCore::registerVendorAsset(VendorAssetData::buildAsset(
        path: 'vendor/capell/inertia-bookings',
        file: 'resources/js/app.js',
        packageName: 'capell-app/theme-inertia-bookings',
    ));

    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $runtime->usesInertia = true;

    $requirements = new VendorBuildAssetContributor(resolve(VendorAssetConditionRegistry::class))
        ->requirements(new FrontendAssetContextData(
            page: null,
            site: null,
            language: null,
            layout: null,
            theme: null,
            runtime: $runtime,
        ));

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0])->toBeInstanceOf(FrontendAssetRequirementData::class)
        ->and($requirements[0]->kind)->toBe(FrontendAssetRequirementData::KIND_JS)
        ->and($requirements[0]->source)->toBe('resources/js/app.js');
});

it('keeps vendor javascript out of blade-only runtimes', function (): void {
    CapellCore::registerVendorAsset(VendorAssetData::buildAsset(
        path: 'vendor/capell/inertia-bookings',
        file: 'resources/js/app.js',
        packageName: 'capell-app/theme-inertia-bookings',
    ));

    $requirements = new VendorBuildAssetContributor(resolve(VendorAssetConditionRegistry::class))
        ->requirements(new FrontendAssetContextData(
            page: null,
            site: null,
            language: null,
            layout: null,
            theme: null,
            runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
        ));

    expect($requirements)->toBe([]);
});
