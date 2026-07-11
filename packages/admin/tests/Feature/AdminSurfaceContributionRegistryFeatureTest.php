<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;

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

it('filters contributions by type', function (): void {
    CapellAdmin::clearAdminSurfaceContributions();

    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(SettingsPage::class));

    expect(CapellAdmin::getAdminSurfaceContributions(AdminSurfaceContributionType::Page))->toHaveCount(1)
        ->and(CapellAdmin::getAdminSurfaceContributions(AdminSurfaceContributionType::Resource))->toBe([]);
});
