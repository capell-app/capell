<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;

beforeEach(function (): void {
    config()->set('app.url', 'https://example.test');
    config()->set('capell.extensions.marketplace_instance_id', 'instance-123');
});

it('allows an expired paid extension that was previously valid for this site', function (): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'name' => 'SEO Suite',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'expired',
        'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => [
            'activation_id' => 'act_123',
            'composer_name' => 'capell-app/seo-suite',
            'expires_at' => now()->subDay()->toIso8601String(),
            'instance_id' => 'instance-123',
            'signature_algorithm' => 'hmac-sha256',
            'signature_issued_at' => now()->subMinute()->toIso8601String(),
            'signature' => 'signed',
            'installed_receipt' => validInstalledReceipt(),
        ],
    ]);

    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect($gate->allowed)->toBeTrue()
        ->and($gate->reason)->toBe('expired_but_previously_valid');
});

it('blocks an expired paid extension when the issuer key is untrusted or malformed', function (string $key): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'name' => 'SEO Suite',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'expired',
        'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => ['installed_receipt' => validInstalledReceipt()],
    ]);
    config()->set('capell.extensions.marketplace_receipt_public_keys', ['test-issuer' => $key]);

    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect($gate->allowed)->toBeFalse()
        ->and($gate->reason)->toBe('expired_without_activation');
})->with([
    'untrusted key' => base64_encode(str_repeat('x', 32)),
    'malformed key' => 'invalid base64',
]);

/** @return array<string, mixed> */
function validInstalledReceipt(): array
{
    $keys = sodium_crypto_sign_seed_keypair(str_repeat("\x02", 32));
    config()->set('capell.extensions.marketplace_receipt_public_keys', ['test-issuer' => base64_encode(sodium_crypto_sign_publickey($keys))]);
    $receipt = [
        'receipt_version' => 2,
        'purpose' => 'capell-installed-receipt',
        'receipt_id' => 'receipt-123',
        'composer_name' => 'capell-app/seo-suite',
        'package_version' => '1.0.0',
        'package_identity' => 'identity-123',
        'instance_id' => 'instance-123',
        'domain' => 'example.test',
        'issued_at' => now()->subDay()->toIso8601String(),
        'runtime_valid_until' => now()->addDays(20)->toIso8601String(),
        'perpetual_installed_runtime' => true,
        'runtime_revoked' => false,
        'signature' => ['algorithm' => 'ed25519', 'keyId' => 'test-issuer', 'value' => ''],
    ];
    $receipt['signature']['value'] = base64_encode(sodium_crypto_sign_detached(CanonicalizeProjectBuildManifestSigningInputAction::run($receipt), sodium_crypto_sign_secretkey($keys)));

    return $receipt;
}

it('blocks an expired paid extension without an issuer receipt', function (): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'name' => 'SEO Suite',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'expired',
        'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => [
            'activation_id' => 'act_123',
            'composer_name' => 'capell-app/seo-suite',
            'expires_at' => now()->addDay()->toIso8601String(),
            'instance_id' => 'instance-123',
            'signature_algorithm' => 'hmac-sha256',
            'signature_issued_at' => now()->subMinute()->toIso8601String(),
            'signature' => 'signed',
        ],
    ]);

    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect($gate->allowed)->toBeFalse()
        ->and($gate->reason)->toBe('expired_without_activation');
});

it('blocks an expired paid extension without a signed activation', function (): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/forms-pro',
        'name' => 'Forms Pro',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'expired',
        'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => null,
    ]);

    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect($gate->allowed)->toBeFalse()
        ->and($gate->reason)->toBe('expired_without_activation');
});

it('blocks a paid extension when the marketplace explicitly disallows runtime use', function (): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/commerce-pro',
        'name' => 'Commerce Pro',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'active',
        'marketplace_runtime_allowed' => false,
        'marketplace_signed_activation' => [
            'activation_id' => 'act_789',
            'signature_algorithm' => 'hmac-sha256',
            'signature_issued_at' => now()->subMinute()->toIso8601String(),
            'signature' => 'signed',
        ],
    ]);

    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect($gate->allowed)->toBeFalse()
        ->and($gate->reason)->toBe('marketplace_runtime_disallowed');
});

it('blocks an expired paid extension with a malformed activation payload', function (): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/forms-pro',
        'name' => 'Forms Pro',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'expired',
        'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => ['activation_id' => 'act_123', 'signature' => 'signed'],
    ]);

    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect($gate->allowed)->toBeFalse()
        ->and($gate->reason)->toBe('expired_without_activation');
});
