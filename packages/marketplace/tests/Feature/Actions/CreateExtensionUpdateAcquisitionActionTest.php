<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\CreateExtensionUpdateAcquisitionAction;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Models\MarketplaceInstance;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function updatableListing(bool $isPaid = false, ?MarketplaceInstallEligibilityData $eligibility = null): ExtensionListingData
{
    return new ExtensionListingData(
        slug: 'seo-suite',
        name: 'SEO Suite',
        composerName: 'capell-app/seo-suite',
        kind: 'package',
        description: null,
        priceCents: $isPaid ? 9900 : 0,
        isPaid: $isPaid,
        forkRepoUrl: null,
        productId: null,
        latestVersion: '2.4.0',
        installEligibilityPolicy: $eligibility,
    );
}

it('builds a free update acquisition from the latest version without calling the marketplace', function (): void {
    Http::fake();

    $acquisition = CreateExtensionUpdateAcquisitionAction::run(
        listing: updatableListing(),
        currentVersion: '2.1.0',
    );

    expect($acquisition->composerName)->toBe('capell-app/seo-suite')
        ->and($acquisition->versionConstraint)->toBe('^2.4.0')
        ->and($acquisition->composerCommand)->toBe('composer require capell-app/seo-suite:^2.4.0')
        ->and($acquisition->requiresDeployment)->toBeFalse()
        ->and($acquisition->metadata['authorization_source'] ?? null)->toBe('local_free_policy')
        ->and($acquisition->authorizationEligibilityPolicy?->canUpdate)->toBeTrue();

    Http::assertNothingSent();
});

it('authorizes a protected update through the upgrade authorization endpoint', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-update',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    config()->set('capell-marketplace.marketplace.base_url', 'https://marketplace.test/api');

    Http::fake([
        'marketplace.test/api/extensions/upgrade-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/seo-suite',
                'version_constraint' => '^2.4.0',
                'repository_url' => 'https://packages.test/seo-suite',
                'composer_auth' => ['http-basic' => ['packages.test' => ['username' => 'token']]],
                'signed_activation' => ['signature' => 'abc'],
                'metadata' => ['channel' => 'stable'],
                'expires_at' => '2026-09-01T00:00:00+00:00',
            ],
        ]),
    ]);

    $acquisition = CreateExtensionUpdateAcquisitionAction::run(
        listing: updatableListing(isPaid: true, eligibility: new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Authorized,
            canInstall: true,
            canUpdate: true,
        )),
        currentVersion: '2.1.0',
    );

    expect($acquisition->versionConstraint)->toBe('^2.4.0')
        ->and($acquisition->repositoryUrl)->toBe('https://packages.test/seo-suite')
        ->and($acquisition->requiresDeployment)->toBeTrue()
        ->and($acquisition->composerAuth)->toBe(['http-basic' => ['packages.test' => ['username' => 'token']]])
        ->and($acquisition->signedActivation)->toBe(['signature' => 'abc'])
        ->and($acquisition->metadata)->toBe(['channel' => 'stable']);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/extensions/upgrade-authorization')
        && $request['current_version'] === '2.1.0'
        && $request['composer_name'] === 'capell-app/seo-suite');
});

it('refuses to authorize an update the marketplace policy says cannot be updated', function (): void {
    Http::fake();

    expect(fn (): mixed => CreateExtensionUpdateAcquisitionAction::run(
        listing: updatableListing(isPaid: true, eligibility: new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Authorized,
            canInstall: true,
            canUpdate: false,
        )),
        currentVersion: '2.1.0',
    ))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('refuses to authorize an update when the marketplace blocks the extension outright', function (): void {
    Http::fake();

    expect(fn (): mixed => CreateExtensionUpdateAcquisitionAction::run(
        listing: updatableListing(isPaid: true, eligibility: new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Blocked,
            blockReason: 'entitlement_required',
            canUpdate: true,
        )),
        currentVersion: '2.1.0',
    ))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});
