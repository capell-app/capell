<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Actions\VerifyMarketplaceSignedActivationAction;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplacePayloadSigner;

it('verifies signed marketplace activations for the matching extension and instance', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'name' => 'SEO Suite',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
    ]);
    $activation = signedMarketplaceActivation([
        'activation_id' => 'act_123',
        'composer_name' => 'capell-app/seo-suite',
        'expires_at' => now()->addDay()->toIso8601String(),
        'instance_id' => 'instance-123',
        'signature_algorithm' => 'hmac-sha256',
        'signature_issued_at' => now()->subMinute()->toIso8601String(),
    ]);

    expect(VerifyMarketplaceSignedActivationAction::run($extension, $activation))->toBeTrue();
});

it('rejects copied marketplace activations for another extension', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/forms-pro',
        'name' => 'Forms Pro',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
    ]);
    $activation = signedMarketplaceActivation([
        'activation_id' => 'act_123',
        'composer_name' => 'capell-app/seo-suite',
        'expires_at' => now()->addDay()->toIso8601String(),
        'instance_id' => 'instance-123',
        'signature_algorithm' => 'hmac-sha256',
        'signature_issued_at' => now()->subMinute()->toIso8601String(),
    ]);

    expect(VerifyMarketplaceSignedActivationAction::run($extension, $activation))->toBeFalse();
});

it('does not fall back to the global signing secret for unknown instances', function (): void {
    config(['capell-marketplace.marketplace.webhook_secret' => 'fallback-secret']);

    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'name' => 'SEO Suite',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
    ]);
    $activation = signedMarketplaceActivation([
        'activation_id' => 'act_123',
        'composer_name' => 'capell-app/seo-suite',
        'expires_at' => now()->addDay()->toIso8601String(),
        'instance_id' => 'missing-instance',
        'signature_algorithm' => 'hmac-sha256',
        'signature_issued_at' => now()->subMinute()->toIso8601String(),
    ], 'fallback-secret');

    expect(VerifyMarketplaceSignedActivationAction::run($extension, $activation))->toBeFalse();
});

/**
 * @param  array<string, mixed>  $activation
 * @return array<string, mixed>
 */
function signedMarketplaceActivation(array $activation, string $secret = 'secret-value'): array
{
    $activation['signature'] = resolve(MarketplacePayloadSigner::class)->signature($activation, $secret);

    return $activation;
}
