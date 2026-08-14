<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Enums\ExtensionProviderRecoveryStateEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;

it('fails closed for quarantined providers and audits an authorised re-enable', function (): void {
    $packageName = 'vendor/recovery-extension';

    CapellCore::registerPackage($packageName, version: '1.0.0');
    CapellCore::markPackageInstalled($packageName);
    CapellCore::markPackageProviderQuarantined(
        name: $packageName,
        provider: 'Vendor\\Recovery\\RecoveryServiceProvider',
        reason: 'Provider registration failed during worker boot.',
    );

    $quarantined = CapellExtension::query()->where('composer_name', $packageName)->firstOrFail();

    $quarantineEvents = $quarantined->provider_recovery_events ?? [];

    expect($quarantined->status)->toBe(ExtensionStatusEnum::Failed)
        ->and($quarantined->provider_recovery_state)->toBe(ExtensionProviderRecoveryStateEnum::Quarantined)
        ->and($quarantined->provider_recovery_provider)->toBe('Vendor\\Recovery\\RecoveryServiceProvider')
        ->and($quarantined->provider_recovery_events)->toHaveCount(1)
        ->and(data_get($quarantineEvents, '0.event'))->toBe('quarantined')
        ->and(CapellCore::isPackageInstalled($packageName))->toBeFalse()
        ->and(CapellCore::isPackageEnabled($packageName))->toBeFalse()
        ->and(ResolveExtensionRuntimeGateAction::run($quarantined)->allowed)->toBeFalse()
        ->and(ResolveExtensionRuntimeGateAction::run($quarantined)->reason)->toBe('provider_quarantined');

    EnablePackageAction::run(CapellCore::getPackage($packageName), 'admin-42');

    $reenabled = $quarantined->refresh();
    $events = $reenabled->provider_recovery_events ?? [];

    expect($reenabled?->status)->toBe(ExtensionStatusEnum::Enabled)
        ->and($reenabled?->provider_recovery_state)->toBe(ExtensionProviderRecoveryStateEnum::Healthy)
        ->and($reenabled?->provider_recovery_provider)->toBeNull()
        ->and($reenabled?->provider_recovery_reason)->toBeNull()
        ->and($events)->toHaveCount(2)
        ->and(data_get($events, '1.event'))->toBe('reenabled')
        ->and(data_get($events, '1.actor'))->toBe('admin-42')
        ->and(CapellCore::isPackageEnabled($packageName))->toBeTrue();
});

it('blocks runtime when the provider recovery state is not healthy', function (): void {
    $extension = new CapellExtension([
        'composer_name' => 'vendor/missing-recovery-state',
        'status' => ExtensionStatusEnum::Enabled,
        'provider_recovery_state' => null,
    ]);

    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect($gate->allowed)->toBeFalse()
        ->and($gate->reason)->toBe('provider_quarantined');
});
