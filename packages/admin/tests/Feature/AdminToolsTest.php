<?php

declare(strict_types=1);

use Capell\Admin\Livewire\Header\AdminTools;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Support\Security\LockdownStore;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    config()->set('capell.lockdown.file', storage_path('framework/testing/admin-tools-lockdown.json'));
    config()->set('filesystems.disks.page_cache.root', storage_path('framework/testing/admin-tools-page-cache'));
    File::delete(config('capell.lockdown.file'));
    File::deleteDirectory(config('filesystems.disks.page_cache.root'));
});

afterEach(function (): void {
    File::delete(config('capell.lockdown.file'));
    File::deleteDirectory(config('filesystems.disks.page_cache.root'));

    $preservedCachePaths = glob(storage_path('framework/testing/admin-tools-page-cache.capell-live-*'));

    foreach (is_array($preservedCachePaths) ? $preservedCachePaths : [] as $path) {
        File::deleteDirectory($path);
    }
});

it('mounts without throwing for unauthenticated users', function (): void {
    Livewire::test(AdminTools::class)->assertOk();
});

it('rebuilds site and notifies if tree is broken', function (): void {
    test()->actingAsAdmin();
    Livewire::test(AdminTools::class)->call('rebuildSite')->assertOk();
});

it('renders one tools entry with labeled dropdown rows', function (): void {
    Permission::create(['name' => 'View:SiteHealthPage', 'guard_name' => 'web']);
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);

    $settings = AdminSettings::instance();
    $settings->enable_header_navigation_tree = true;
    $settings->save();

    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SiteHealthPage', 'View:UpgradePage');

    Livewire::test(AdminTools::class)
        ->assertSee(__('capell-admin::button.site_tools'))
        ->assertSee(__('capell-admin::button.browse_pages'))
        ->assertSee(__('capell-admin::button.clear_cache'))
        ->assertSee(__('capell-admin::button.build_frontend'))
        ->assertSee(__('capell-admin::button.rebuild_site'))
        ->assertSee(__('capell-admin::button.build_frontend_tooltip'))
        ->assertSee(__('capell-admin::button.rebuild_site_tooltip'))
        ->assertSee(__('capell-admin::button.site_health_tooltip'))
        ->assertSee(__('capell-admin::button.upgrade_capell_tooltip'))
        ->assertSee(__('capell-admin::navigation.site_health'))
        ->assertSee(__('capell-admin::button.upgrade_capell'));
});

it('hides the header navigation tree when disabled in admin settings', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_header_navigation_tree = false;
    $settings->save();

    $rendered = Blade::render('@include("capell-admin::components.header.actions")');

    expect($rendered)
        ->not->toContain('capell-admin::header.navigation-tree')
        ->toContain('capell-admin::header.admin-tools');
});

it('shows the header navigation tree when enabled in admin settings', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_header_navigation_tree = true;
    $settings->save();

    test()->actingAsAdmin();

    $rendered = Blade::render('@include("capell-admin::components.header.actions")');

    expect($rendered)
        ->toContain('capell-admin::header.navigation-tree')
        ->toContain('capell-admin::header.admin-tools');
});

it('enables and disables lockdown from the header tools', function (): void {
    test()->actingAsAdmin();

    Livewire::test(AdminTools::class)
        ->assertSee(__('capell-admin::button.enable_lockdown'))
        ->call('enableLockdown')
        ->assertSet('lockdownActive', true)
        ->assertDispatched('capell-lockdown-state-changed')
        ->assertSee(__('capell-admin::button.disable_lockdown'))
        ->call('disableLockdown')
        ->assertSet('lockdownActive', false)
        ->assertDispatched('capell-lockdown-state-changed');

    expect(resolve(LockdownStore::class)->active())->toBeFalse();
});

it('renders the lockdown banner with live status state', function (): void {
    $inactive = Blade::render('@include("capell-admin::components.header.lockdown-banner")');

    test()->actingAsAdmin();
    Livewire::test(AdminTools::class)->call('enableLockdown');

    $active = Blade::render('@include("capell-admin::components.header.lockdown-banner")');

    expect($inactive)->toContain('active: false')
        ->and($active)->toContain('active: true')
        ->and($active)->toContain('role="status"')
        ->and($active)->toContain(__('capell-admin::message.lockdown_banner'));
});
