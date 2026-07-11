<?php

declare(strict_types=1);

use Capell\Admin\Support\AdminPanelEntrypoint;

it('uses admin as the default admin panel entrypoint path', function (): void {
    config(['capell-admin.path' => null]);

    expect(AdminPanelEntrypoint::path())->toBe('admin');
});

it('normalizes configured admin panel entrypoint paths', function (): void {
    config(['capell-admin.path' => '/123/']);

    expect(AdminPanelEntrypoint::path())->toBe('123');
});

it('uses admin as the fallback when no admin domain is configured', function (): void {
    config([
        'capell-admin.domain' => null,
        'capell-admin.path' => '/',
    ]);

    expect(AdminPanelEntrypoint::path())->toBe('admin');
});

it('allows the admin panel at the root of a configured admin domain', function (): void {
    config([
        'capell-admin.domain' => 'admin.example.com',
        'capell-admin.path' => '/',
    ]);

    expect(AdminPanelEntrypoint::domain())->toBe('admin.example.com')
        ->and(AdminPanelEntrypoint::path())->toBe('');
});

it('normalizes blank admin panel domains', function (): void {
    config(['capell-admin.domain' => '  ']);

    expect(AdminPanelEntrypoint::domain())->toBeNull();
});
