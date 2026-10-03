<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;

it('allows an expired paid extension that was previously valid for this site', function (): void {
    bindRuntimeGateEntitlements(
        fn (CapellExtension $extension, array $installedReceipt): bool => $extension->composer_name === $installedReceipt['composer_name']
            && $installedReceipt['receipt_id'] === 'receipt-123',
    );

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

it('blocks an expired paid extension when the extension entitlements reject or cannot verify its receipt', function (Closure $verify): void {
    bindRuntimeGateEntitlements($verify);

    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'name' => 'SEO Suite',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'expired',
        'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => ['installed_receipt' => validInstalledReceipt()],
    ]);

    $gate = ResolveExtensionRuntimeGateAction::run($extension);

    expect($gate->allowed)->toBeFalse()
        ->and($gate->reason)->toBe('expired_without_activation');
})->with([
    'rejected' => [fn (): bool => false],
    'verification throws' => [function (): bool {
        throw new RuntimeException('Verification unavailable.');
    }],
]);

function bindRuntimeGateEntitlements(Closure $verify): void
{
    app()->bind(ExtensionEntitlements::class, fn (): ExtensionEntitlements => new readonly class($verify) implements ExtensionEntitlements
    {
        public function __construct(private Closure $verify) {}

        public function licenceDecision(string $slug, string $action, string $domain): ExtensionLicenceDecisionData
        {
            throw new LogicException('The runtime gate must not request licence decisions.');
        }

        public function verifyActivation(CapellExtension $extension, array $installedReceipt): bool
        {
            return ($this->verify)($extension, $installedReceipt);
        }
    });
}

/** @return array<string, mixed> */
function validInstalledReceipt(): array
{
    return [
        'receipt_version' => 1,
        'receipt_id' => 'receipt-123',
        'composer_name' => 'capell-app/seo-suite',
        'package_version' => '1.0.0',
        'package_identity' => 'identity-123',
        'instance_id' => 'instance-123',
        'domain' => 'example.test',
        'issued_at' => now()->subMonth()->toIso8601String(),
        'perpetual_installed_runtime' => true,
        'runtime_revoked' => false,
        'signature' => 'signed',
    ];
}

it('blocks an expired paid extension when no activation verifier is registered', function (): void {
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
