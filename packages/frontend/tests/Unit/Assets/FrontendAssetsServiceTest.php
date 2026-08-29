<?php

declare(strict_types=1);

use Capell\Core\Enums\AssetEnum;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Frontend\Data\FrontendAssetData;
use Capell\Frontend\Support\Assets\FrontendAssetsService;

it('emits a receipt for direct frontend asset registration', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    $asset = new FrontendAssetData(component: 'vendor::asset');

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/frontend-assets', 'frontend', 'Vendor\\Frontend\\Provider'),
        fn (): FrontendAssetsService => (new FrontendAssetsService)->registerAsset(AssetEnum::Page, $asset),
    );

    expect($receipts->forPackage('vendor/frontend-assets'))->toHaveCount(1)
        ->and($receipts->forPackage('vendor/frontend-assets')[0]->key)->toBe('frontend-asset:Page');
});

it('registers and resolves frontend assets by enum or name', function (): void {
    $service = new FrontendAssetsService;
    $asset = new FrontendAssetData(component: 'capell-frontend::components.page');

    expect($service->registerAsset(AssetEnum::Page, $asset))->toBe($service)
        ->and($service->hasAsset('Page'))->toBeTrue()
        ->and($service->getAssets()->all())->toBe(['Page' => $asset])
        ->and($service->getAsset(AssetEnum::Page))->toBe($asset)
        ->and($service->getAsset('page'))->toBe($asset);
});

it('rejects missing frontend assets', function (): void {
    expect(fn (): mixed => (new FrontendAssetsService)->getAsset('missing'))
        ->toThrow(InvalidArgumentException::class, "Asset with name 'Missing' does not exist.");
});
