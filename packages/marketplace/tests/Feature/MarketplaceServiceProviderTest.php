<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\ExtensionsPageExtender;
use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Filament\Extenders\MarketplaceExtensionsPageExtender;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

uses(CreatesAdminUser::class);

it('registers the marketplace package metadata', function (): void {
    expect(CapellCore::hasPackage(MarketplaceServiceProvider::$packageName))->toBeTrue()
        ->and(CapellCore::getPackage(MarketplaceServiceProvider::$packageName)->serviceProviderClass)
        ->toBe(MarketplaceServiceProvider::class);
});

it('registers marketplace pages in the admin surface', function (): void {
    expect(CapellAdmin::getAdminSurfaceRegistry()->pages())
        ->toContain(MarketplacePage::class)
        ->toContain(MarketplaceExtensionDetailPage::class);
});

it('does not register marketplace as an extensions page header action', function (): void {
    $actionNames = collect(resolve(ExtensionsPageActionRegistry::class)->headerActions(new ExtensionsPage))
        ->flatMap(fn (Action|ActionGroup $action): array => $action instanceof ActionGroup ? array_values($action->getFlatActions()) : [$action])
        ->map(fn (Action $action): string => expectPresent($action->getName()))
        ->all();

    expect($actionNames)->not->toContain('browseMarketplace');
});

it('registers marketplace extensions page actions', function (): void {
    $registry = resolve(ExtensionsPageActionRegistry::class);
    $headerActionNames = collect($registry->headerActions(new ExtensionsPage))
        ->map(fn (Action $action): string => expectPresent($action->getName()))
        ->all();
    $groupActionNames = collect($registry->headerActionGroupActions(new ExtensionsPage))
        ->map(fn (Action $action): string => expectPresent($action->getName()))
        ->all();

    expect($headerActionNames)
        ->toContain('openMarketplace')
        ->toContain('marketplaceInstallOperations')
        ->not->toContain('connectMarketplaceAccount')
        ->and($groupActionNames)
        ->toContain('connectMarketplaceAccount')
        ->toContain('createMarketplaceAccount');
});

it('registers the installed extension catalogue metadata provider', function (): void {
    $providers = collect(app()->tagged(ExtensionCatalogueMetadataProvider::TAG));

    expect($providers->contains(
        fn (ExtensionCatalogueMetadataProvider $provider): bool => $provider instanceof MarketplaceCatalogueRecordProvider,
    ))->toBeTrue();
});

it('keeps marketplace table warmup without rendering extensions page alert content', function (): void {
    $extenders = collect(app()->tagged(ExtensionsPageExtender::TAG));
    $extender = $extenders->first(fn (ExtensionsPageExtender $extender): bool => $extender instanceof MarketplaceExtensionsPageExtender);

    expect($extenders->contains(fn (ExtensionsPageExtender $extender): bool => $extender instanceof MarketplaceExtensionsPageExtender))
        ->toBeTrue()
        ->and($extender->getBeforeTableContent(new ExtensionsPage))->toBe([]);
});
