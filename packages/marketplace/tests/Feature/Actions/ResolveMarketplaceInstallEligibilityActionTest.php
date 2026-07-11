<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\ResolveMarketplaceInstallEligibilityAction;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Models\MarketplaceInstance;

it('allows free installs without a connected account', function (): void {
    $eligibility = ResolveMarketplaceInstallEligibilityAction::run(
        listing: marketplaceEligibilityListing(isPaid: false),
        instance: null,
    );

    expect($eligibility->state)->toBe(MarketplaceInstallState::FreeAvailable)
        ->and($eligibility->canInstall)->toBeTrue();
});

it('blocks protected installs without a connected account', function (): void {
    $eligibility = ResolveMarketplaceInstallEligibilityAction::run(
        listing: marketplaceEligibilityListing(),
        instance: null,
        remoteEligibility: marketplaceAuthorizedEligibility(),
    );

    expect($eligibility->state)->toBe(MarketplaceInstallState::Blocked)
        ->and($eligibility->blockReason)->toBe('account_required');
});

it('blocks protected installs with an unverified account email', function (): void {
    $instance = MarketplaceInstance::query()->create([
        'instance_id' => 'instance-unverified',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'last_heartbeat_at' => now(),
    ]);

    $eligibility = ResolveMarketplaceInstallEligibilityAction::run(
        listing: marketplaceEligibilityListing(),
        instance: $instance,
        remoteEligibility: marketplaceAuthorizedEligibility(),
    );

    expect($eligibility->state)->toBe(MarketplaceInstallState::Blocked)
        ->and($eligibility->blockReason)->toBe('email_verification_required');
});

it('blocks protected installs with a verified account but missing marketplace policy', function (): void {
    $instance = MarketplaceInstance::query()->create([
        'instance_id' => 'instance-missing-policy',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    $eligibility = ResolveMarketplaceInstallEligibilityAction::run(
        listing: marketplaceEligibilityListing(),
        instance: $instance,
    );

    expect($eligibility->state)->toBe(MarketplaceInstallState::Blocked)
        ->and($eligibility->blockReason)->toBe('entitlement_required')
        ->and($eligibility->missingPolicy)->toBeTrue();
});

it('allows protected installs with a verified account and marketplace authorization', function (): void {
    $instance = MarketplaceInstance::query()->create([
        'instance_id' => 'instance-authorized',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    $eligibility = ResolveMarketplaceInstallEligibilityAction::run(
        listing: marketplaceEligibilityListing(),
        instance: $instance,
        remoteEligibility: marketplaceAuthorizedEligibility(),
    );

    expect($eligibility->state)->toBe(MarketplaceInstallState::Authorized)
        ->and($eligibility->canInstall)->toBeTrue();
});

it('treats blocking remote eligibility as protected even when pricing flags are absent', function (): void {
    $eligibility = ResolveMarketplaceInstallEligibilityAction::run(
        listing: marketplaceEligibilityListing(isPaid: false),
        instance: null,
        remoteEligibility: new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Blocked,
            blockReason: 'entitlement_required',
        ),
    );

    expect($eligibility->state)->toBe(MarketplaceInstallState::Blocked)
        ->and($eligibility->blockReason)->toBe('account_required');
});

function marketplaceEligibilityListing(bool $isPaid = true): ExtensionListingData
{
    return new ExtensionListingData(
        slug: 'protected-suite',
        name: 'Protected Suite',
        composerName: 'capell-app/protected-suite',
        kind: 'package',
        description: null,
        priceCents: $isPaid ? 9900 : 0,
        isPaid: $isPaid,
        forkRepoUrl: null,
        productId: null,
    );
}

function marketplaceAuthorizedEligibility(): MarketplaceInstallEligibilityData
{
    return new MarketplaceInstallEligibilityData(
        state: MarketplaceInstallState::Authorized,
        canInstall: true,
    );
}
