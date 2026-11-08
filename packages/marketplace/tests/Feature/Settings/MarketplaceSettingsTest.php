<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Settings\MarketplaceSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

function grantMarketplaceOwnerSettingsAccess(): void
{
    Permission::findOrCreate('View:ExtensionsPage', 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:ExtensionsPage');
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');
    test()->authenticatedUser()->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
}

it('keeps owner contact settings inside the authenticated marketplace admin surface', function (): void {
    grantMarketplaceOwnerSettingsAccess();

    Livewire::test(MarketplacePage::class)
        ->assertSuccessful()
        ->assertActionVisible('ownerContactSettings')
        ->mountAction('ownerContactSettings')
        ->assertMountedActionModalSee(__('capell-marketplace::settings.owner_contact'))
        ->assertMountedActionModalSee(__('capell-marketplace::settings.marketing_notifications_helper'));

    expect(MarketplacePage::canAccess())->toBeTrue();
});

it('saves contact details and leaves every notification preference off by default', function (): void {
    grantMarketplaceOwnerSettingsAccess();

    Livewire::test(MarketplacePage::class)
        ->mountAction('ownerContactSettings')
        ->fillForm([
            'owner_name' => 'Ben Capell',
            'owner_email' => 'owner@example.test',
            'owner_organisation' => 'Capell Ltd',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $settings = resolve(MarketplaceSettings::class);

    expect($settings->owner_name)->toBe('Ben Capell')
        ->and($settings->owner_email)->toBe('owner@example.test')
        ->and($settings->owner_organisation)->toBe('Capell Ltd')
        ->and($settings->security_notifications_enabled)->toBeFalse()
        ->and($settings->bug_notifications_enabled)->toBeFalse()
        ->and($settings->marketing_notifications_enabled)->toBeFalse();
});

it('rejects an invalid owner email before saving settings', function (): void {
    grantMarketplaceOwnerSettingsAccess();

    Livewire::test(MarketplacePage::class)
        ->mountAction('ownerContactSettings')
        ->fillForm(['owner_email' => 'not-an-email'])
        ->callMountedAction()
        ->assertHasFormErrors(['owner_email']);
});

it('persists explicit opt-in and allows the owner to revoke it', function (): void {
    grantMarketplaceOwnerSettingsAccess();

    $component = Livewire::test(MarketplacePage::class)
        ->mountAction('ownerContactSettings')
        ->fillForm([
            'owner_email' => 'owner@example.test',
            'marketing_notifications_enabled' => true,
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(resolve(MarketplaceSettings::class)->marketing_notifications_enabled)->toBeTrue();

    $component
        ->mountAction('ownerContactSettings')
        ->fillForm([
            'owner_email' => 'owner@example.test',
            'marketing_notifications_enabled' => false,
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(resolve(MarketplaceSettings::class)->marketing_notifications_enabled)->toBeFalse();
});

it('refuses owner contact settings to a user who can view but not manage extensions', function (string $permission): void {
    Permission::findOrCreate($permission, 'web');
    test()->actingAsUser();
    test()->authenticatedUser()->givePermissionTo($permission);

    $component = Livewire::test(MarketplacePage::class)
        ->assertSuccessful()
        ->assertActionHidden('ownerContactSettings')
        ->assertActionExists('ownerContactSettings', fn (Action $action): bool => ! $action->isAuthorized());

    $component
        ->call('mountAction', 'ownerContactSettings')
        ->set('mountedActions.0.data.owner_email', 'forged@example.test')
        ->call('callMountedAction')
        ->assertNotNotified(__('capell-marketplace::settings.settings_saved'));

    expect(resolve(MarketplaceSettings::class)->owner_email)->toBeNull();
})->with([
    'marketplace viewer' => ['View:MarketplacePage'],
    'extensions viewer' => ['View:ExtensionsPage'],
]);
