<?php

declare(strict_types=1);

use Capell\Admin\Actions\ActivateLockdownAction;
use Capell\Admin\Actions\DeactivateLockdownAction;
use Capell\Admin\Livewire\Header\AdminTools;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Capell\Core\Support\Security\LockdownStaticCacheSwitcher;
use Capell\Core\Support\Security\LockdownStore;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
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

it('skips static cache switching for multi-node lockdown activation and deactivation', function (): void {
    config(['capell.multi_node' => true, 'cache.default' => 'shared-test', 'cache.stores.shared-test.driver' => 'redis']);
    $cache = new Repository(new ArrayStore);
    // Partial: the admin tools view reads the default store too, which must resolve to the same shared repository.
    Cache::partialMock()->shouldReceive('store')->andReturn($cache);
    test()->actingAsAdmin();
    $payload = ActivateLockdownAction::run(test()->authenticatedUser());
    expect($payload['static_cache'])->toBe(['skipped' => 'multi_node'])
        ->and($cache->get('capell.security.lockdown'))->toMatchArray(['active' => true, 'static_cache' => ['skipped' => 'multi_node']]);
    $banner = Blade::render('@include("capell-admin::components.header.lockdown-banner")');
    expect($banner)->toContain(__('capell-admin::message.lockdown_multi_node_cache_warning'));
    DeactivateLockdownAction::run();
    expect($cache->get('capell.security.lockdown'))->toMatchArray(['active' => false])->and(resolve(LockdownStore::class)->active())->toBeFalse();
    Livewire::test(AdminTools::class)->call('enableLockdown')
        ->assertNotified(__('capell-admin::message.lockdown_enabled') . ' ' . __('capell-admin::message.lockdown_multi_node_cache_warning'));
    DeactivateLockdownAction::run();
});

it('refuses lockdown activation for a guard without a user provider before switching the static cache', function (): void {
    test()->actingAsAdmin();
    $files = Mockery::mock(Filesystem::class);
    $files->shouldNotReceive('exists', 'move', 'makeDirectory', 'put');

    app()->instance(LockdownStaticCacheSwitcher::class, new LockdownStaticCacheSwitcher(
        $files,
        resolve(MultiNodeTopologyGuard::class),
        resolve(ReleaseRootWriteGuard::class),
    ));

    expect(fn (): array => ActivateLockdownAction::run(test()->authenticatedUser(), 'lockdown-missing'))
        ->toThrow(RuntimeException::class, __('capell-admin::message.lockdown_invalid_provider'))
        ->and(resolve(LockdownStore::class)->active())->toBeFalse();
});

it('refuses activation when the guard provider model does not match the authenticated user', function (): void {
    test()->actingAsAdmin();
    config()->set('auth.guards.lockdown-mismatch', ['driver' => 'session', 'provider' => 'lockdown-mismatch']);
    config()->set('auth.providers.lockdown-mismatch', ['driver' => 'eloquent', 'model' => GenericUser::class]);

    $files = Mockery::mock(Filesystem::class);
    $files->shouldNotReceive('exists', 'move', 'makeDirectory', 'put');

    app()->instance(LockdownStaticCacheSwitcher::class, new LockdownStaticCacheSwitcher($files, resolve(MultiNodeTopologyGuard::class), resolve(ReleaseRootWriteGuard::class)));

    expect(fn (): array => ActivateLockdownAction::run(test()->authenticatedUser(), 'lockdown-mismatch'))
        ->toThrow(RuntimeException::class, __('capell-admin::message.lockdown_invalid_provider'))
        ->and(resolve(LockdownStore::class)->active())->toBeFalse();
});

it('activates lockdown for a modelless database driver provider', function (): void {
    test()->actingAsAdmin();
    config()->set('auth.guards.lockdown-database', ['driver' => 'session', 'provider' => 'lockdown-database']);
    config()->set('auth.providers.lockdown-database', ['driver' => 'database', 'table' => 'users']);

    expect(ActivateLockdownAction::run(test()->authenticatedUser(), 'lockdown-database'))->toBeArray()
        ->and(resolve(LockdownStore::class)->active())->toBeTrue();
});
