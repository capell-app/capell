<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;

it('registers built in resources and pages as contributions', function (): void {
    $registerConfigurators = new ReflectionMethod(CapellAdminPlugin::class, 'registerConfigurators');
    $registerConfigurators->invoke(CapellAdminPlugin::make());

    $registry = CapellAdmin::getAdminSurfaceRegistry();

    expect($registry->pages())->toContain(SettingsPage::class)
        ->and($registry->resources())->toContain(SiteResource::class)
        ->and($registry->widgets())->toContain(ListPagesFilamentWidget::class)
        ->and($registry->configuratorsForGroup('Pages'))->not->toBeEmpty();
});

it('registers admin surface contributions directly', function (): void {
    CapellAdmin::clearAdminSurfaceContributions();

    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(SettingsPage::class));
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(SiteResource::class, 'Site'));

    expect(CapellAdmin::getAdminSurfaceRegistry()->pages())->toBe([SettingsPage::class])
        ->and(CapellAdmin::getAdminSurfaceRegistry()->resourcesForGroup('Site'))->toBe(['default' => SiteResource::class]);
});

it('emits a receipt at the direct admin surface boundary', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/admin-surface', 'admin', 'Vendor\\AdminServiceProvider'),
        function (): void {
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(SettingsPage::class));
        },
    );

    expect($receipts->forPackage('vendor/admin-surface'))->toHaveCount(1)
        ->and($receipts->forPackage('vendor/admin-surface')[0]->key)->toBe(SettingsPage::class);
});

it('filters contributions by type', function (): void {
    CapellAdmin::clearAdminSurfaceContributions();

    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(SettingsPage::class));

    expect(CapellAdmin::getAdminSurfaceContributions(AdminSurfaceContributionType::Page))->toHaveCount(1)
        ->and(CapellAdmin::getAdminSurfaceContributions(AdminSurfaceContributionType::Resource))->toBe([]);
});
