<?php

declare(strict_types=1);

use Capell\Core\Actions\Marketplace\ResolveExtensionLicenceDecisionAction;
use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Enums\ExtensionLicenceStatus;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Marketplace\MarketplacePayloadSigner;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceExtensionEntitlements;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('resolves licence decisions through the signed marketplace client when the marketplace is installed', function (): void {
    config([
        'app.url' => 'https://client.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.instance.id' => null,
        'capell-marketplace.marketplace.webhook_secret' => null,
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => '018f47a2-62da-7ca4-b732-bd3c715db1bf',
        'signing_secret_encrypted' => 'test-signing-secret',
        'last_heartbeat_at' => now(),
    ]);
    resolve(MarketplaceInstanceResolver::class)->forget();

    // The marketplace rejects any licence-decision request that is not signed
    // by a registered instance, so an unsigned call must fail here as it does live.
    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite/licence-decision' => fn (Request $request) => match (true) {
            ! $request->hasHeader('X-Capell-Instance') || ! $request->hasHeader('X-Capell-Signature') => Http::response(['message' => 'Invalid instance signature.'], 401),
            ! is_string($request['domain'] ?? null) || $request['domain'] === '' => Http::response(['message' => 'The domain field is required.'], 422),
            default => Http::response(['data' => ['licence_status' => 'active', 'can_install' => true]]),
        },
    ]);

    $decision = ResolveExtensionLicenceDecisionAction::run('seo-suite', 'install', 'client.test');

    expect($decision->licenceStatus)->toBe(ExtensionLicenceStatus::Active)
        ->and($decision->canInstall)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://marketplace.test/api/extensions/seo-suite/licence-decision'
        && $request->header('X-Capell-Instance') === ['018f47a2-62da-7ca4-b732-bd3c715db1bf']
        && str_starts_with($request->header('X-Capell-Signature')[0] ?? '', 'sha256=')
        && $request['action'] === 'install'
        && $request['domain'] === 'client.test');
    Http::assertSentCount(1);
});

it('sends this site host as the domain when the caller has none', function (): void {
    config([
        'app.url' => 'https://client.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.instance.id' => null,
        'capell-marketplace.marketplace.webhook_secret' => null,
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => '018f47a2-62da-7ca4-b732-bd3c715db1bf',
        'signing_secret_encrypted' => 'test-signing-secret',
        'last_heartbeat_at' => now(),
    ]);
    resolve(MarketplaceInstanceResolver::class)->forget();

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite/licence-decision' => Http::response(['data' => ['licence_status' => 'active', 'can_rate' => true]]),
    ]);

    ResolveExtensionLicenceDecisionAction::run('seo-suite', 'rate', '');

    Http::assertSent(fn (Request $request): bool => $request['domain'] === 'client.test');
});

it('binds the marketplace extension entitlements in place of the core default', function (): void {
    expect(resolve(ExtensionEntitlements::class))->toBeInstanceOf(MarketplaceExtensionEntitlements::class);
});

it('keeps an expired paid extension running only on a well-formed receipt signed for this site', function (Closure $mutate, bool $allowed): void {
    $instanceId = '018f47a2-62da-7ca4-b732-bd3c715db1bf';

    MarketplaceInstance::query()->create([
        'instance_id' => $instanceId,
        'signing_secret_encrypted' => 'test-signing-secret',
        'last_heartbeat_at' => now(),
    ]);

    $receipt = $mutate([
        'receipt_version' => 1,
        'receipt_id' => 'receipt-123',
        'composer_name' => 'capell-app/seo-suite',
        'package_version' => '1.0.0',
        'package_identity' => 'sha256:package',
        'instance_id' => $instanceId,
        'domain' => 'client.test',
        'issued_at' => now()->subMonth()->toIso8601String(),
        'perpetual_installed_runtime' => true,
        'runtime_revoked' => false,
    ]);
    $receipt['signature'] ??= resolve(MarketplacePayloadSigner::class)->signature($receipt, 'test-signing-secret');

    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'name' => 'SEO Suite',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'expired',
        'marketplace_runtime_allowed' => true,
        'marketplace_signed_activation' => ['installed_receipt' => $receipt],
    ]);

    expect(ResolveExtensionRuntimeGateAction::run($extension)->allowed)->toBe($allowed);
})->with([
    'valid signed receipt' => [fn (array $receipt): array => $receipt, true],
    'tampered signature' => [fn (array $receipt): array => [...$receipt, 'signature' => 'sha256=forged'], false],
    'revoked receipt' => [fn (array $receipt): array => [...$receipt, 'runtime_revoked' => true], false],
    'non-perpetual receipt' => [fn (array $receipt): array => [...$receipt, 'perpetual_installed_runtime' => false], false],
    'another extension' => [fn (array $receipt): array => [...$receipt, 'composer_name' => 'capell-app/forms-pro'], false],
    'unknown receipt version' => [fn (array $receipt): array => [...$receipt, 'receipt_version' => 2], false],
    'missing package identity' => [fn (array $receipt): array => array_diff_key($receipt, ['package_identity' => true]), false],
]);
