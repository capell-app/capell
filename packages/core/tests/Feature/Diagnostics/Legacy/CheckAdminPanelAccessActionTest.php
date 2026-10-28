<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Tests\Support\LegacyAdapter;

function runLegacyAdminPanelAccessCheckForTest(): DoctorCheckResultData
{
    $result = LegacyAdapter::invoke('Capell\\Core\\Actions\\Diagnostics\\CheckAdminPanelAccessAction', 'handle');
    throw_unless($result instanceof DoctorCheckResultData, RuntimeException::class, 'Legacy diagnostics did not return a check result.');

    return $result;
}

it('keeps the legacy super-admin role fallback compatible', function (): void {
    $originalRoles = config('capell.roles');
    $originalShieldRole = config('filament-shield.super_admin.name');
    $roles = is_array($originalRoles) ? $originalRoles : [];
    unset($roles['super_admin']);

    config([
        'capell.roles' => $roles,
        'filament-shield.super_admin.name' => 'shield-super-admin',
    ]);

    try {
        $result = runLegacyAdminPanelAccessCheckForTest();

        expect($result->evidence['role_name'] ?? null)->toBe('shield-super-admin');
    } finally {
        config([
            'capell.roles' => $originalRoles,
            'filament-shield.super_admin.name' => $originalShieldRole,
        ]);
    }
});

it('keeps the legacy capell role precedence compatible', function (): void {
    config(['capell.roles.super_admin' => 'capell-configured-admin']);
    config(['filament-shield.super_admin.name' => 'shield-super-admin']);

    $result = runLegacyAdminPanelAccessCheckForTest();

    expect($result->evidence['role_name'] ?? null)->toBe('capell-configured-admin');
});
