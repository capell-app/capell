<?php

declare(strict_types=1);

use Capell\Admin\Enums\EditorEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Core\Facades\CapellCore;
use Capell\Frontend\Facades\Frontend;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('settings');

beforeEach(function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');
});

it('visit settings page', function (): void {
    get(SettingsPage::getUrl())
        ->assertSuccessful();
});

it('visit settings page when frontend cache setting is missing', function (): void {
    DB::table('settings')
        ->where('group', 'frontend')
        ->where('name', 'cache_enabled')
        ->delete();

    Livewire::test(SettingsPage::class)
        ->assertSuccessful();
});

it('first-party settings schemas appear on the main settings page', function (): void {
    expect(CapellAdmin::settings()->html_editor)->toBe(EditorEnum::RichEditor)
        ->and(CapellCore::settings()->default_locale)->toBe('en')
        ->and(Frontend::settings()->cache_enabled)->toBeTrue();

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertFormFieldExists('core.default_locale')
        ->assertFormFieldExists('admin.html_editor')
        ->assertFormFieldExists('frontend.cache_enabled');
});

it('saves frontend settings through the main settings page', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertSchemaComponentStateSet('frontend.cache_ttl', 3600)
        ->fillForm([
            'frontend' => ['cache_ttl' => 7200],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas('settings', [
        'group' => 'frontend',
        'name' => 'cache_ttl',
        'payload' => json_encode(7200),
    ]);
});
