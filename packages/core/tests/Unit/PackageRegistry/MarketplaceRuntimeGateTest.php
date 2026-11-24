<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Marketplace\TrustedMarketplaceIssuerKeys;

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
    app()->instance(TrustedMarketplaceIssuerKeys::class, new TrustedMarketplaceIssuerKeys(keys: ['test-issuer' => $key]));

    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect($gate->allowed)->toBeFalse()
        ->and($gate->reason)->toBe('expired_without_activation');
})->with([
    'untrusted key' => base64_encode(str_repeat('x', 32)),
    'malformed key' => 'invalid base64',
]);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validInstalledReceipt(array $overrides = []): array
{
    $keys = sodium_crypto_sign_seed_keypair(str_repeat("\x02", 32));
    app()->instance(TrustedMarketplaceIssuerKeys::class, new TrustedMarketplaceIssuerKeys(keys: ['test-issuer' => base64_encode(sodium_crypto_sign_publickey($keys))]));
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
    $receipt = [...$receipt, ...$overrides];
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

/** @param array<string, mixed> $receipt */
function paidExtensionWithReceipt(?string $status, array $receipt): CapellExtension
{
    return CapellExtension::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'name' => 'SEO Suite',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => $status,
        'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => ['installed_receipt' => $receipt],
    ]);
}

// Catches an unactivated, over-limit or lapsed site being broken although the issuer genuinely signed its receipt.
it('keeps a genuinely licensed install running when its site is not activated or its receipt has lapsed', function (?string $status, array $overrides): void {
    $gate = ResolveExtensionRuntimeGateAction::run(paidExtensionWithReceipt($status, validInstalledReceipt($overrides)));

    expect($gate->allowed)->toBeTrue()
        ->and($gate->reason)->toBe('genuine_receipt_awaiting_site_licence');
})->with([
    'receipt for another site' => ['expired', ['domain' => 'another-site.com']],
    'runtime window ended' => ['expired', ['issued_at' => now()->subDays(40)->toIso8601String(), 'runtime_valid_until' => now()->subDays(12)->toIso8601String()]],
    'site reported as a domain mismatch' => ['domain_mismatch', ['domain' => 'another-site.com']],
    'site not yet verified' => ['unverified', []],
    'no runtime status yet' => [null, ['instance_id' => 'another-instance']],
]);

// Catches a forged, altered or revoked receipt being let through by the softened gate.
it('still stops an install whose receipt was altered or revoked', function (?string $status, Closure $receipt, string $reason): void {
    $gate = ResolveExtensionRuntimeGateAction::run(paidExtensionWithReceipt($status, $receipt()));

    expect($gate->allowed)->toBeFalse()
        ->and($gate->reason)->toBe($reason);
})->with([
    'domain changed after signing' => ['domain_mismatch', fn (): array => [...validInstalledReceipt(), 'domain' => 'another-site.com'], 'domain_mismatch'],
    'receipt for a different package' => ['expired', fn (): array => validInstalledReceipt(['composer_name' => 'capell-app/forms-pro']), 'expired_without_activation'],
    'issuer revoked the runtime' => [null, fn (): array => validInstalledReceipt(['runtime_revoked' => true]), 'missing_marketplace_activation'],
    'revoked status' => ['revoked', validInstalledReceipt(...), 'revoked'],
]);
