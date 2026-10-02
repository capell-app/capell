<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Marketplace\MarketplacePayloadSigner;
use Capell\Marketplace\Actions\PersistMarketplaceActivationAction;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceActivationContext;

it('persists the encrypted authorization and makes expired-runtime grace reachable', function (): void {
    $composerName = 'capell-app/paid-runtime-extension';
    $receipt = [
        'receipt_version' => 1,
        'receipt_id' => 'receipt-123',
        'composer_name' => $composerName,
        'package_version' => '1.0.0',
        'package_identity' => 'sha256:package',
        'instance_id' => 'instance-123',
        'domain' => 'example.test',
        'issued_at' => now()->subDay()->toIso8601String(),
        'perpetual_installed_runtime' => true,
        'runtime_revoked' => false,
    ];
    $receipt['signature'] = resolve(MarketplacePayloadSigner::class)->signature($receipt, 'test-signing-secret');
    $signedActivation = [
        'runtime_status' => 'expired',
        'installed_receipt' => $receipt,
    ];

    $extension = CapellExtension::query()->create([
        'composer_name' => $composerName,
        'name' => 'Paid Runtime Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_allowed' => true,
    ]);
    $attempt = MarketplaceInstallAttempt::query()->create([
        'composer_name' => $composerName,
        'extension_slug' => 'paid-runtime-extension',
        'extension_name' => 'Paid Runtime Extension',
        'kind' => 'plugin',
        'status' => MarketplaceInstallIntentStatus::Running,
        'context' => MarketplaceActivationContext::encryptedInto([], $signedActivation),
    ]);

    expect(json_encode($attempt->context, JSON_THROW_ON_ERROR))->not->toContain($receipt['signature']);

    PersistMarketplaceActivationAction::run($attempt);
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'test-signing-secret',
        'last_heartbeat_at' => now(),
    ]);

    $extension->refresh();
    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect(capellJsonKeysSorted($extension->marketplace_signed_activation))->toBe(capellJsonKeysSorted($signedActivation))
        ->and($extension->marketplace_runtime_status)->toBe('expired')
        ->and($extension->marketplace_activation_checked_at)->not->toBeNull()
        ->and($gate->allowed)->toBeTrue()
        ->and($gate->reason)->toBe('expired_but_previously_valid');
});

it('does nothing when an authorization supplied no signed activation', function (): void {
    $attempt = MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/free-extension',
        'extension_slug' => 'free-extension',
        'extension_name' => 'Free Extension',
        'kind' => 'plugin',
        'status' => MarketplaceInstallIntentStatus::Running,
        'context' => [],
    ]);

    expect(PersistMarketplaceActivationAction::run($attempt))->toBeNull();
});
