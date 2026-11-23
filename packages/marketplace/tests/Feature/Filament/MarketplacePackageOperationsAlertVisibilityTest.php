<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Filament\Widgets\MarketplacePackageOperationsAlertFilamentWidget;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('shows package operations, with extension names and failure excerpts, only to people who can manage extensions', function (): void {
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');
    Permission::findOrCreate('View:Page', 'web');

    test()->actingAs(test()->createUserWithPermission('View:Page'));

    expect(MarketplacePackageOperationsAlertFilamentWidget::canView())->toBeFalse();

    test()->actingAs(test()->createUserWithPermission(ExtensionsPage::MANAGE_PERMISSION));

    expect(MarketplacePackageOperationsAlertFilamentWidget::canView())->toBeTrue();
});
