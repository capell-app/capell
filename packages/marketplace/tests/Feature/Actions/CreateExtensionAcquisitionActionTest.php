<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\CreateExtensionAcquisitionAction;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Models\MarketplaceInstance;
use Illuminate\Support\Facades\Http;

it('returns composer acquisition instructions for free extensions without marketplace authorization', function (): void {
    $listing = new ExtensionListingData(
        slug: 'seo-suite',
        name: 'SEO Suite',
        composerName: 'capell-app/seo-suite',
        kind: 'package',
        description: null,
        priceCents: 0,
        isPaid: false,
        forkRepoUrl: null,
        productId: null,
        latestVersion: '2.1.0',
    );

    Http::fake();

    $acquisition = CreateExtensionAcquisitionAction::run($listing);

    expect($acquisition->composerCommand)->toBe('composer require capell-app/seo-suite:^2.1.0')
        ->and($acquisition->requiresDeployment)->toBeFalse()
        ->and($acquisition->signedActivation)->toBe([])
        ->and($acquisition->metadata)->toBe(['authorization_source' => 'local_free_policy']);

    Http::assertNothingSent();
});

it('records install intent telemetry after protected marketplace authorization succeeds', function (): void {
    $listing = new ExtensionListingData(
        slug: 'seo-suite',
        name: 'SEO Suite',
        composerName: 'capell-app/seo-suite',
        kind: 'package',
        description: null,
        priceCents: 9900,
        isPaid: true,
        forkRepoUrl: null,
        productId: null,
        latestVersion: '2.1.0',
        installOptions: [
            [
                'key' => 'starter_content',
                'type' => 'checkbox',
                'label' => 'Starter content',
            ],
        ],
        installEligibilityPolicy: new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Authorized,
            canInstall: true,
        ),
    );

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => '00000000-0000-4000-8000-000000000001',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000001',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email' => 'ben@example.com',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/seo-suite',
                'version_constraint' => '^2.1',
                'composer_auth' => [
                    'github-oauth' => [
                        'github.com' => 'ghp_secret_token',
                    ],
                ],
            ],
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    $acquisition = CreateExtensionAcquisitionAction::run(
        listing: $listing,
        installOptions: ['starter_content' => true, 'ignored' => true],
    );

    expect($acquisition->composerCommand)->toBe('composer require capell-app/seo-suite:^2.1')
        ->and($acquisition->composerAuth)->toBe([
            'github-oauth' => [
                'github.com' => 'ghp_secret_token',
            ],
        ]);

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://marketplace.test/api/extensions/install-intents'
            && $payload['event_type'] === 'install_intent'
            && $payload['source'] === 'marketplace'
            && $payload['instance_id'] === '00000000-0000-4000-8000-000000000001'
            && $payload['account_id'] === 'acct_123'
            && ! array_key_exists('domain', $payload)
            && ! array_key_exists('claimed_domains', $payload)
            && ! array_key_exists('publicly_verified_domains', $payload)
            && $payload['slug'] === 'seo-suite'
            && $payload['composer_name'] === 'capell-app/seo-suite'
            && $payload['version_constraint'] === '^2.1'
            && $payload['install_options'] === ['starter_content' => true]
            && $payload['signature_algorithm'] === 'hmac-sha256'
            && is_string($payload['signature']);
    });
});

it('uses repository URLs only from protected install authorization responses', function (): void {
    $listing = new ExtensionListingData(
        slug: 'private-suite',
        name: 'Private Suite',
        composerName: 'capell-app/private-suite',
        kind: 'package',
        description: null,
        priceCents: 9900,
        isPaid: true,
        forkRepoUrl: 'https://github.com/capell-marketplace/private-suite',
        productId: null,
        latestVersion: '2.1.0',
        installEligibilityPolicy: new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Authorized,
            canInstall: true,
        ),
    );

    config([
        'capell-marketplace.instance.id' => '00000000-0000-4000-8000-000000000001',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000001',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/private-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/private-suite',
                'version_constraint' => '^2.1',
            ],
        ]),
    ]);

    $acquisition = CreateExtensionAcquisitionAction::run($listing);

    expect($acquisition->repositoryUrl)->toBeNull()
        ->and($acquisition->requiresDeployment)->toBeFalse();
});

it('rejects an authorization for a different composer package', function (): void {
    $listing = new ExtensionListingData(
        slug: 'private-suite',
        name: 'Private Suite',
        composerName: 'capell-app/private-suite',
        kind: 'package',
        description: null,
        priceCents: 9900,
        isPaid: true,
        forkRepoUrl: null,
        productId: null,
        latestVersion: '2.1.0',
        installEligibilityPolicy: new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Authorized,
            canInstall: true,
        ),
    );

    config([
        'capell-marketplace.instance.id' => '00000000-0000-4000-8000-000000000002',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000002',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_substitution_test',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/private-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'attacker/substitute-package',
                'version_constraint' => '^1.0',
            ],
        ]),
    ]);

    CreateExtensionAcquisitionAction::run($listing);
})->throws(UnexpectedValueException::class);
