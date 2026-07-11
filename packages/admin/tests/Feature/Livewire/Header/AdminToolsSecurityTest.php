<?php

declare(strict_types=1);

use Capell\Admin\Livewire\Header\AdminTools;
use Capell\Core\Support\Security\LockdownStore;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

uses(CreatesAdminUser::class)->group('security', 'admin-tools');

beforeEach(function (): void {
    config()->set('capell.lockdown.file', storage_path('framework/testing/admin-tools-security-lockdown.json'));
    config()->set('filesystems.disks.page_cache.root', storage_path('framework/testing/admin-tools-security-page-cache'));
    File::delete(config('capell.lockdown.file'));
    File::deleteDirectory(config('filesystems.disks.page_cache.root'));
});

afterEach(function (): void {
    File::delete(config('capell.lockdown.file'));
    File::deleteDirectory(config('filesystems.disks.page_cache.root'));

    $preservedCachePaths = glob(storage_path('framework/testing/admin-tools-security-page-cache.capell-live-*'));

    foreach (is_array($preservedCachePaths) ? $preservedCachePaths : [] as $path) {
        File::deleteDirectory($path);
    }
});

it('mount() does not throw for a guest', function (): void {
    (new AdminTools)->mount();
    expect(true)->toBeTrue();
});

it('mount() does not throw for a non-admin user', function (): void {
    $user = test()->createUser();
    Auth::login($user);

    (new AdminTools)->mount();
    expect(true)->toBeTrue();
});

it('only renders the header tools menu for global admins', function (): void {
    Auth::login(test()->createUser());

    expect((new AdminTools)->canViewTools())->toBeFalse();

    Auth::login(test()->createUserWithRole('super_admin'));

    expect((new AdminTools)->canViewTools())->toBeTrue();
});

/** @return list<string> */
dataset('admin_tools_methods', [
    ['rebuildSite'],
    ['buildFrontend'],
    ['clearCache'],
    ['enableLockdown'],
    ['disableLockdown'],
]);

it('rejects :method when invoked directly by a non-admin user', function (string $method): void {
    $user = test()->createUser();
    Auth::login($user);

    $component = new AdminTools;

    $invoke = match ($method) {
        'rebuildSite' => $component->rebuildSite(...),
        'buildFrontend' => $component->buildFrontend(...),
        'clearCache' => $component->clearCache(...),
        'enableLockdown' => $component->enableLockdown(...),
        'disableLockdown' => $component->disableLockdown(...),
        default => throw new InvalidArgumentException('Unknown admin tools method.'),
    };

    expect($invoke)->toThrow(AuthorizationException::class);
})->with('admin_tools_methods');

it('does not treat the default super admin role as global when the role is remapped', function (): void {
    config()->set('capell.roles.super_admin', 'platform-admin');

    $user = test()->createUserWithRole('super_admin');
    Auth::login($user);

    expect((new AdminTools)->rebuildSite(...))->toThrow(AuthorizationException::class);
});

it('keeps the activating admin allowed during lockdown', function (): void {
    $user = test()->createUserWithRole('super_admin');
    Auth::login($user);

    (new AdminTools)->enableLockdown();

    expect(resolve(LockdownStore::class)->canAccessAdmin($user))->toBeTrue();
});

it('rejects lockdown actions from a global admin who is not allowed during lockdown', function (): void {
    $activatingUser = test()->createUserWithRole('super_admin');
    $blockedAdmin = test()->createUserWithRole('super_admin');

    resolve(LockdownStore::class)->activateFor($activatingUser);
    Auth::login($blockedAdmin);

    $component = new AdminTools;

    expect($component->disableLockdown(...))->toThrow(AuthorizationException::class)
        ->and($component->enableLockdown(...))->toThrow(AuthorizationException::class);
});
