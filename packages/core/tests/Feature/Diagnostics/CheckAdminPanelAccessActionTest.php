<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\CheckAdminPanelAccessAction;

it('resolves the super-admin role name through the canonical fallback order', function (): void {
    $originalRoles = config('capell.roles');
    $originalShieldRole = config('filament-shield.super_admin.name');
    $roles = is_array($originalRoles) ? $originalRoles : [];
    unset($roles['super_admin']);

    config([
        'capell.roles' => $roles,
        'filament-shield.super_admin.name' => 'shield-super-admin',
    ]);

    try {
        $result = CheckAdminPanelAccessAction::run();

        expect($result->evidence['role_name'] ?? null)->toBe('shield-super-admin');
    } finally {
        config([
            'capell.roles' => $originalRoles,
            'filament-shield.super_admin.name' => $originalShieldRole,
        ]);
    }
});

it('prefers the explicit capell config over the filament-shield fallback', function (): void {
    config(['capell.roles.super_admin' => 'capell-configured-admin']);
    config(['filament-shield.super_admin.name' => 'shield-super-admin']);

    $result = CheckAdminPanelAccessAction::run();

    expect($result->evidence['role_name'] ?? null)->toBe('capell-configured-admin');
});
