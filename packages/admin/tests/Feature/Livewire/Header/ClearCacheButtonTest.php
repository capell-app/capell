<?php

declare(strict_types=1);

use Capell\Admin\Livewire\Header\ClearCacheButton;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Tests\Support\Models\TeamScopedPanelUser;
use Capell\Core\Models\Site;
use CmsMulti\FilamentClearCache\Jobs\ClearCacheJob;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    Bus::fake([ClearCacheJob::class]);
});

afterEach(function (): void {
    setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    config(['permission.teams' => false]);
});

function clearCacheUserForClearCacheButtonTest(): TeamScopedPanelUser
{
    return TeamScopedPanelUser::query()->create(['name' => 'Clear cache user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
}

it('is the clear-cache button every panel renders, whatever the host configured', function (): void {
    expect(config('filament-clear-cache.livewireComponentClass'))->toBe(ClearCacheButton::class);
});

it('refuses a site-scoped editor who calls clear and clears nothing', function (): void {
    $editor = clearCacheUserForClearCacheButtonTest();
    $role = Role::query()->firstOrCreate(['name' => 'site-editor', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate('Update:Page', 'web'));

    $editor->assignRoleForSite(Site::factory()->withTranslations()->create(), $role);
    setPermissionsTeamId(null);

    Livewire::actingAs($editor)
        ->test(ClearCacheButton::class)
        ->assertSet('visible', false)
        ->assertDontSeeHtml('wire:click="clear"')
        ->call('clear')
        ->assertForbidden();

    Bus::assertNotDispatchedAfterResponse(ClearCacheJob::class);
});

it('sends a snapshot replayed under the vendor component name to the guarded button', function (): void {
    expect(resolve('livewire.finder')->resolveClassComponentClassName(AdminServiceProvider::VENDOR_CLEAR_CACHE_COMPONENT))->toBe(ClearCacheButton::class);

    $editor = clearCacheUserForClearCacheButtonTest();
    $editor->assignRoleForSite(Site::factory()->withTranslations()->create(), Role::query()->firstOrCreate(['name' => 'site-editor', 'guard_name' => 'web']));
    setPermissionsTeamId(null);

    // As a page rendered before Capell guarded the button would have named it.
    Livewire::actingAs($editor)
        ->test(AdminServiceProvider::VENDOR_CLEAR_CACHE_COMPONENT)
        ->call('clear')
        ->assertForbidden();

    Bus::assertNotDispatchedAfterResponse(ClearCacheJob::class);
});

it('lets a global administrator clear the caches', function (): void {
    $administrator = clearCacheUserForClearCacheButtonTest();
    $administrator->assignGlobalRole((string) config('capell.roles.super_admin', 'super_admin'));

    Livewire::actingAs($administrator)
        ->test(ClearCacheButton::class)
        ->assertSet('visible', true)
        ->assertSeeHtml('wire:click="clear"')
        ->call('clear')
        ->assertOk();

    Bus::assertDispatchedAfterResponse(ClearCacheJob::class);
});
