<?php

declare(strict_types=1);

use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallState;

it('preserves Capell Membership as an actionable block reason', function (): void {
    $eligibility = MarketplaceInstallEligibilityData::fromPayload([
        'state' => 'capell_all_required',
        'can_install' => false,
        'reason' => 'capell_all_required',
    ]);

    expect($eligibility->state)->toBe(MarketplaceInstallState::Blocked)
        ->and($eligibility->blockReason)->toBe('capell_all_required');
});

it('allows account-linked installs when marketplace marks the extension eligible', function (): void {
    $eligibility = MarketplaceInstallEligibilityData::fromPayload([
        'state' => 'account_linked_allowed',
        'requirements' => [
            'account' => 'linked',
        ],
    ], protectedInstall: true);

    expect($eligibility->state)->toBe(MarketplaceInstallState::Authorized)
        ->and($eligibility->blocksInstall())->toBeFalse()
        ->and($eligibility->requirements)->toBe([
            'account' => 'linked',
        ]);
});

it('normalizes legacy public verification requirements to entitlement blocks', function (): void {
    $eligibility = MarketplaceInstallEligibilityData::fromPayload([
        'state' => 'blocked',
        'block_reason' => 'public_verification_required',
    ], protectedInstall: true);

    expect($eligibility->state)->toBe(MarketplaceInstallState::Blocked)
        ->and($eligibility->blockReason)->toBe('entitlement_required')
        ->and($eligibility->blocksInstall())->toBeTrue();
});

it('interprets purchase activation incompatible and missing policy states', function (mixed $payload, MarketplaceInstallState $state, ?string $reason): void {
    $eligibility = MarketplaceInstallEligibilityData::fromPayload($payload, protectedInstall: true);

    expect($eligibility->state)->toBe($state)
        ->and($eligibility->blockReason)->toBe($reason);
})->with([
    'purchase required' => ['purchase_required', MarketplaceInstallState::PurchaseRequired, null],
    'activation required' => ['activation_required', MarketplaceInstallState::ActivationRequired, null],
    'incompatible' => ['incompatible', MarketplaceInstallState::Incompatible, null],
    'missing policy' => [null, MarketplaceInstallState::Blocked, 'missing_policy'],
]);
