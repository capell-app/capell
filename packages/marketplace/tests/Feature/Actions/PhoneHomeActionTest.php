<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Actions\PhoneHomeAction;
use Capell\Marketplace\Actions\RunMarketplaceHeartbeatAction;
use Capell\Marketplace\Data\PhoneHomeResultData;
use Capell\Marketplace\Models\MarketplaceInstance;
use Illuminate\Support\Facades\Http;

it('sends signed installed package telemetry during heartbeat', function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.marketplace.webhook_url' => 'https://example.test/capell/marketplace/webhook',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000001',
        'signing_secret_encrypted' => 'test-signing-secret',
        'connection_metadata' => ['connection_session_id' => 'session-safe'],
        'last_heartbeat_at' => now(),
    ]);

    CapellCore::registerPackage(
        name: 'capell-app/seo-suite',
        type: PackageTypeEnum::Plugin,
        version: '1.2.3',
        description: 'SEO Suite',
    );
    CapellCore::forcePackageInstalled('capell-app/seo-suite');
    CapellExtension::query()
        ->updateOrCreate(['composer_name' => 'capell-app/seo-suite'], [
            'name' => 'SEO Suite',
            'version' => '1.2.3',
            'status' => ExtensionStatusEnum::Enabled,
            'is_paid_marketplace_extension' => true,
            'marketplace_runtime_status' => 'active',
            'marketplace_runtime_allowed' => true,
            'marketplace_signed_activation' => [
                'activation_id' => 'licence-1-site-1',
                'activation_nonce' => 'activation-nonce',
                'signature_algorithm' => 'hmac-sha256',
                'signature_issued_at' => now()->subMinute()->toIso8601String(),
                'extension_id' => 1,
                'extension_slug' => 'seo-suite',
                'composer_name' => 'capell-app/seo-suite',
                'package_version' => '1.2.3',
                'manifest_version' => 3,
                'manifest_hash' => str_repeat('a', 64),
                'package_identity' => 'identity-123',
                'instance_id' => '00000000-0000-4000-8000-000000000001',
                'domain' => 'example.test',
                'licence_status' => 'active',
                'effective_license' => 'premium',
                'effective_certification_status' => 'partner',
                'trust_tier' => 'partner',
                'private_docs_entitled' => true,
                'runtime_allowed' => true,
                'issued_at' => now()->subMinute()->toIso8601String(),
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'signature' => 'sha256=signed',
            ],
        ]);

    Http::fake([
        'https://capell.test/api/v1/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000001',
                'updates' => [],
                'advisories' => [],
                'commercial' => [
                    'purchases' => [['name' => 'Capell Membership', 'status' => 'active']],
                    'renewal_url' => 'https://capell.test/customer/packages',
                ],
            ],
        ]),
    ]);

    expect(PhoneHomeAction::run())->toBeTrue();

    expect(MarketplaceInstance::query()->firstOrFail()->connection_metadata)->toMatchArray([
        'connection_session_id' => 'session-safe',
        'commercial' => [
            'purchases' => [['name' => 'Capell Membership', 'status' => 'active']],
            'renewal_url' => 'https://capell.test/customer/packages',
        ],
    ]);

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://capell.test/api/v1/instances/heartbeat'
            && $payload['event_type'] === 'extension_health_report'
            && $payload['source'] === 'heartbeat'
            && $payload['instance_id'] === '00000000-0000-4000-8000-000000000001'
            && ! array_key_exists('is_local', $payload)
            && $payload['signature_algorithm'] === 'hmac-sha256'
            && is_string($payload['signature'])
            && str_starts_with($payload['signature'], 'sha256=')
            && collect($payload['installed'])->contains(
                fn (array $package): bool => $package['name'] === 'capell-app/seo-suite'
                    && $package['version'] === '1.2.3',
            )
            && collect($payload['installed'])->contains(
                fn (array $package): bool => $package['composer_name'] === 'capell-app/seo-suite'
                    && $package['paid'] === true
                    && $package['licence_status'] === 'active'
                    && $package['runtime_allowed'] === true
                    && $package['marketplace_activation']['package_identity'] === 'identity-123',
            );
    });
});

it('sends local heartbeat telemetry without local eligibility context', function (): void {
    config([
        'app.url' => 'http://localhost',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.marketplace.webhook_url' => 'https://example.test/capell/marketplace/webhook',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000001',
        'signing_secret_encrypted' => 'test-signing-secret',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://capell.test/api/v1/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000001',
                'updates' => [],
                'advisories' => [],
            ],
        ]),
    ]);

    expect(PhoneHomeAction::run())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://capell.test/api/v1/instances/heartbeat'
            && ! array_key_exists('is_local', $request->data()));
});

it('reports a clear heartbeat failure when the marketplace webhook URL is not configured', function (): void {
    Http::fake();

    config([
        'app.url' => 'http://localhost',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.marketplace.webhook_url' => null,
    ]);

    $result = RunMarketplaceHeartbeatAction::run();

    expect($result->successful)->toBeFalse()
        ->and($result->failureMessage)->toContain('marketplace webhook URL could not be resolved');

    Http::assertNothingSent();
});

it('reports a clear heartbeat failure when the marketplace URL is not configured', function (): void {
    Http::fake();

    config([
        'capell-marketplace.marketplace.base_url' => null,
    ]);

    $result = RunMarketplaceHeartbeatAction::run();

    expect($result->successful)->toBeFalse()
        ->and($result->failureMessage)->toContain('marketplace URL is not configured');

    Http::assertNothingSent();
});

it('requires a connected marketplace instance before sending heartbeat telemetry', function (): void {
    Http::fake();

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.marketplace.webhook_url' => 'https://example.test/capell/marketplace/webhook',
        'capell-marketplace.instance.id' => null,
    ]);

    $result = RunMarketplaceHeartbeatAction::run();

    expect($result->successful)->toBeFalse()
        ->and($result->failureMessage)->toContain('not connected to Capell Marketplace');

    Http::assertNothingSent();
});

it('does not bootstrap a heartbeat when the marketplace omits the signing secret', function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.marketplace.webhook_url' => 'https://example.test/capell/marketplace/webhook',
        'capell-marketplace.instance.id' => '00000000-0000-4000-8000-000000000002',
    ]);

    Http::fake([
        'https://capell.test/api/v1/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000002',
                'updates' => [],
                'advisories' => [],
            ],
        ]),
    ]);

    $result = RunMarketplaceHeartbeatAction::run();

    expect($result->successful)->toBeFalse()
        ->and($result->failureMessage)->toContain('did not include a signing secret')
        ->and(MarketplaceInstance::query()->where('instance_id', '00000000-0000-4000-8000-000000000002')->exists())->toBeFalse();
});

it('rejects heartbeat responses for a different connected instance', function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.marketplace.webhook_url' => 'https://example.test/capell/marketplace/webhook',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000001',
        'signing_secret_encrypted' => 'test-signing-secret',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://capell.test/api/v1/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000099',
                'signing_secret' => 'other-secret',
                'updates' => [],
                'advisories' => [],
            ],
        ]),
    ]);

    $result = RunMarketplaceHeartbeatAction::run();

    expect($result->successful)->toBeFalse()
        ->and($result->failureMessage)->toContain('did not confirm the connected instance ID');
});

it('keeps failure details isolated to the operation that produced them', function (): void {
    config(['capell-marketplace.marketplace.base_url' => null]);

    $failed = RunMarketplaceHeartbeatAction::run();

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.marketplace.webhook_url' => 'https://example.test/capell/marketplace/webhook',
        'capell-marketplace.instance.id' => '00000000-0000-4000-8000-000000000002',
    ]);

    Http::fake([
        'https://capell.test/api/v1/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000002',
                'signing_secret' => 'test-signing-secret',
                'updates' => [],
                'advisories' => [],
            ],
        ]),
    ]);

    $succeeded = RunMarketplaceHeartbeatAction::run();

    expect($failed->successful)->toBeFalse()
        ->and($failed->failureMessage)->toContain('marketplace URL is not configured')
        ->and($succeeded->successful)->toBeTrue()
        ->and($succeeded->failureMessage)->toBeNull()
        ->and($failed->failureMessage)->toContain('marketplace URL is not configured');
});

it('preserves the boolean phone home action contract through the result action', function (): void {
    RunMarketplaceHeartbeatAction::shouldRun()
        ->once()
        ->andReturn(new PhoneHomeResultData(successful: true));

    expect(PhoneHomeAction::run())->toBeTrue();
});

it('supports the standard action fake contract for typed heartbeat results', function (): void {
    $expected = new PhoneHomeResultData(
        successful: false,
        failureMessage: 'Operation-scoped failure.',
    );

    RunMarketplaceHeartbeatAction::shouldRun()
        ->once()
        ->andReturn($expected);

    expect(RunMarketplaceHeartbeatAction::run())->toBe($expected);
});

it('preserves the standard action fake contract for boolean phone home calls', function (): void {
    PhoneHomeAction::shouldRun()
        ->once()
        ->andReturn(false);

    expect(PhoneHomeAction::run())->toBeFalse();
});
