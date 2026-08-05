<?php

declare(strict_types=1);

use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Filament\Support\MarketplaceInstallActionPresenter;

function presenter(): MarketplaceInstallActionPresenter
{
    return resolve(MarketplaceInstallActionPresenter::class);
}

it('reports an installed extension with a newer version as update available', function (): void {
    expect(presenter()->state([
        'is_installed' => true,
        'has_update_available' => true,
    ]))->toBe(MarketplaceInstallState::UpdateAvailable);
});

it('keeps an installed extension without a newer version as installed', function (): void {
    expect(presenter()->state([
        'is_installed' => true,
        'has_update_available' => false,
    ]))->toBe(MarketplaceInstallState::Installed);
});

it('keeps an outdated extension the marketplace will not update as installed', function (): void {
    expect(presenter()->state([
        'is_installed' => true,
        'has_update_available' => true,
        'install_eligibility_policy' => [
            'state' => 'authorized',
            'can_install' => true,
            'can_update' => false,
            'can_run_existing' => true,
        ],
    ]))->toBe(MarketplaceInstallState::Installed);
});

it('offers the update when the marketplace policy explicitly allows it', function (): void {
    expect(presenter()->state([
        'is_installed' => true,
        'has_update_available' => true,
        'install_eligibility_policy' => [
            'state' => 'authorized',
            'can_install' => true,
            'can_update' => true,
        ],
    ]))->toBe(MarketplaceInstallState::UpdateAvailable);
});

it('does not offer an update while an operation is already running for the extension', function (): void {
    expect(presenter()->state([
        'is_installed' => true,
        'has_update_available' => true,
        'install_in_progress' => true,
    ]))->toBe(MarketplaceInstallState::Installed);
});

it('does not offer an update for an extension the marketplace has blocked', function (): void {
    expect(presenter()->state([
        'is_installed' => true,
        'has_update_available' => true,
        'install_eligibility_policy' => [
            'state' => 'blocked',
            'block_reason' => 'entitlement_required',
        ],
    ]))->toBe(MarketplaceInstallState::Installed);
});

it('leaves the state of an extension that is not installed untouched', function (): void {
    expect(presenter()->state([
        'is_installed' => false,
        'has_update_available' => true,
        'is_paid' => false,
    ]))->toBe(MarketplaceInstallState::FreeAvailable);
});

it('labels and colours the update action distinctly from a fresh install', function (): void {
    $record = [
        'is_installed' => true,
        'has_update_available' => true,
    ];

    expect(presenter()->label($record))->toBe((string) __('capell-marketplace::marketplace.updates.button'))
        ->and(presenter()->color($record))->toBe('warning')
        ->and(presenter()->tooltip($record))->toBe((string) __('capell-marketplace::marketplace.updates.tooltip'))
        ->and(presenter()->blockReason($record))->toBeNull()
        ->and(presenter()->canUpdate($record))->toBeTrue();
});

it('reports that an installed extension without a newer version cannot be updated', function (): void {
    expect(presenter()->canUpdate([
        'is_installed' => true,
        'has_update_available' => false,
    ]))->toBeFalse();
});
