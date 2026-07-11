<?php

declare(strict_types=1);

use Capell\Admin\Http\Middleware\EnforceLockdownAdminAccess;
use Capell\Core\Support\Security\LockdownStore;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(CreatesAdminUser::class)->group('security', 'lockdown');

beforeEach(function (): void {
    config()->set('capell.lockdown.file', storage_path('framework/testing/admin-middleware-lockdown.json'));
    config()->set('filesystems.disks.page_cache.root', storage_path('framework/testing/admin-middleware-page-cache'));
    File::delete(config('capell.lockdown.file'));
    File::deleteDirectory(config('filesystems.disks.page_cache.root'));
});

afterEach(function (): void {
    File::delete(config('capell.lockdown.file'));
    File::deleteDirectory(config('filesystems.disks.page_cache.root'));
});

it('allows normal admin requests while lockdown is inactive', function (): void {
    Auth::login(test()->createUserWithRole('super_admin'));

    $response = resolve(EnforceLockdownAdminAccess::class)->handle(
        Request::create('/admin'),
        fn (): Response => response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

it('blocks other authenticated admins while lockdown is active', function (): void {
    $activatingUser = test()->createUserWithRole('super_admin');
    $otherAdmin = test()->createUserWithRole('super_admin');

    resolve(LockdownStore::class)->activateFor($activatingUser);
    Auth::login($otherAdmin);

    expect(fn (): mixed => resolve(EnforceLockdownAdminAccess::class)->handle(
        Request::create('/admin'),
        fn (): Response => response('ok'),
    ))->toThrow(HttpException::class, __('capell-admin::message.lockdown_admin_locked'));
});

it('allows configured break glass emails while lockdown is active', function (): void {
    $activatingUser = test()->createUserWithRole('super_admin');
    $breakGlassUser = test()->createUserWithRole('super_admin', ['email' => 'owner@example.com']);

    config()->set('capell.lockdown.break_glass_emails', ['owner@example.com']);
    resolve(LockdownStore::class)->activateFor($activatingUser);
    Auth::login($breakGlassUser);

    $response = resolve(EnforceLockdownAdminAccess::class)->handle(
        Request::create('/admin'),
        fn (): Response => response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

it('allows configured break glass user ids while lockdown is active', function (): void {
    $activatingUser = test()->createUserWithRole('super_admin');
    $breakGlassUser = test()->createUserWithRole('super_admin');

    config()->set('capell.lockdown.break_glass_user_ids', [(string) $breakGlassUser->id]);
    resolve(LockdownStore::class)->activateFor($activatingUser);
    Auth::login($breakGlassUser);

    $response = resolve(EnforceLockdownAdminAccess::class)->handle(
        Request::create('/admin'),
        fn (): Response => response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

it('blocks a non-allowed admin through the real admin route stack during lockdown', function (): void {
    $activatingUser = test()->createUserWithRole('super_admin');
    $blockedAdmin = test()->createUserWithRole('super_admin');

    resolve(LockdownStore::class)->activateFor($activatingUser);

    test()->actingAs($blockedAdmin)
        ->get('/admin')
        ->assertStatus(423);
});
