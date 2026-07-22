<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\ExtensionsPageExtender;
use Capell\Admin\Contracts\Extenders\ResourceHeaderActionExtender;
use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Contracts\Themes\PendingThemeInstallProvider;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Bridges\MarketplaceAdminBridge;
use Capell\Marketplace\Filament\Extenders\MarketplaceExtensionsPageExtender;
use Capell\Marketplace\Filament\Extenders\ThemeMarketplaceHeaderActionExtender;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Filament\Pages\ThemeExtensionPage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Filament\Widgets\MarketplacePackageOperationsAlertFilamentWidget;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;
use Capell\Marketplace\Support\PendingMarketplaceThemeInstallProvider;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Spatie\LaravelPackageTools\Package;

uses(CreatesAdminUser::class);

it('registers the marketplace package metadata', function (): void {
    expect(CapellCore::hasPackage(MarketplaceServiceProvider::$packageName))->toBeTrue()
        ->and(CapellCore::getPackage(MarketplaceServiceProvider::$packageName)->serviceProviderClass)
        ->toBe(MarketplaceServiceProvider::class);
});

it('keeps the runtime tracking migration on its historical published filename', function (): void {
    $package = new Package;
    (new MarketplaceServiceProvider(app()))->configurePackage($package);
    $migrationDirectory = dirname(__DIR__, 2) . '/database/migrations';
    $historicalMigrationName = '2026_07_19_000002_add_runtime_tracking_to_marketplace_install_attempts';

    expect($package->migrationFileNames)->toContain($historicalMigrationName)
        ->and($migrationDirectory . '/' . $historicalMigrationName . '.php')->toBeFile()
        ->and($migrationDirectory . '/2026_07_19_000001_add_runtime_tracking_to_marketplace_install_attempts.php')->not->toBeFile();
});

it('registers marketplace pages in the admin surface', function (): void {
    expect(CapellAdmin::getAdminSurfaceRegistry()->pages())
        ->toContain(MarketplacePage::class)
        ->toContain(MarketplaceExtensionDetailPage::class)
        ->toContain(MarketplacePackageOperationsPage::class)
        ->toContain(ThemeExtensionPage::class)
        ->and(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main))
        ->toContain(MarketplacePackageOperationsAlertFilamentWidget::class);
});

it('registers and boots the marketplace admin bridge once', function (): void {
    $registry = resolve(AdminBridgeRegistry::class);
    $tagCount = fn (string $tag, string $class): int => collect(app()->tagged($tag))
        ->filter(fn (object $contribution): bool => $contribution instanceof $class)
        ->count();

    expect($registry->classes(MarketplaceServiceProvider::$packageName))
        ->toBe([MarketplaceAdminBridge::class]);

    CapellAdmin::registerAdminBridge(MarketplaceServiceProvider::$packageName, MarketplaceAdminBridge::class);
    CapellAdmin::bootAdminBridges(MarketplaceServiceProvider::$packageName);

    expect($registry->classes(MarketplaceServiceProvider::$packageName))
        ->toBe([MarketplaceAdminBridge::class])
        ->and($tagCount(ExtensionsPageExtender::TAG, MarketplaceExtensionsPageExtender::class))->toBe(1)
        ->and($tagCount(ExtensionCatalogueMetadataProvider::TAG, MarketplaceCatalogueRecordProvider::class))->toBe(1)
        ->and($tagCount(ResourceHeaderActionExtender::TAG, ThemeMarketplaceHeaderActionExtender::class))->toBe(1)
        ->and($tagCount(PendingThemeInstallProvider::TAG, PendingMarketplaceThemeInstallProvider::class))->toBe(1);
});

it('does not boot marketplace admin contributions when marketplace is disabled', function (): void {
    config()->set('capell-marketplace.enabled', false);
    CapellAdmin::clearAdminSurfaceContributions();

    CapellAdmin::registerAdminBridge('capell-app/marketplace-disabled-test', MarketplaceAdminBridge::class);
    CapellAdmin::bootAdminBridges('capell-app/marketplace-disabled-test');

    expect(CapellAdmin::getAdminSurfaceRegistry()->pages())->toBe([]);
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
    $tableActionNames = collect($registry->tableActions(new ExtensionsPage))
        ->map(fn (Action $action): string => expectPresent($action->getName()))
        ->all();

    expect($headerActionNames)
        ->toContain('openMarketplace')
        ->toContain('marketplaceInstallOperations')
        ->not->toContain('connectMarketplaceAccount')
        ->and($groupActionNames)
        ->toContain('connectMarketplaceAccount')
        ->toContain('createMarketplaceAccount')
        ->and($tableActionNames)
        ->toContain('openMarketplace');
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
