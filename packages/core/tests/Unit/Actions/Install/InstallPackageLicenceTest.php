<?php

declare(strict_types=1);

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Actions\Install\CheckInstallPackageAccessAction;
use Capell\Core\Actions\Install\ResolveInstallPackageLicenceAction;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\InstallPackageLicenceState;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Marketplace\NullExtensionEntitlements;
use Illuminate\Support\Facades\Http;

it('distinguishes explicit licence facts from unknown tiers and public authorization', function (?bool $paid, ?string $tier, ?string $state, InstallPackageLicenceState $expected): void {
    $package = new PackageData(name: 'vendor/example', type: PackageTypeEnum::Plugin, tier: $tier, isPaid: $paid, installState: $state);
    expect(ResolveInstallPackageLicenceAction::run($package))->toBe($expected);
})->with([
    'paid overrides free tier' => [true, 'free', null, InstallPackageLicenceState::Required],
    'free overrides premium tier' => [false, 'premium', null, InstallPackageLicenceState::Free],
    'legacy free tier' => [null, 'free', null, InstallPackageLicenceState::Free],
    'unknown tier' => [null, null, null, InstallPackageLicenceState::Unknown],
    'premium without explicit licence facts' => [null, 'premium', null, InstallPackageLicenceState::Unknown],
    'unavailable free package' => [false, 'free', 'unavailable', InstallPackageLicenceState::Unavailable],
    'public authorized flag is not account proof' => [true, 'premium', 'authorized', InstallPackageLicenceState::Required],
]);

it('preserves catalogue licensing fields through PackageData hydration', function (): void {
    Http::fake(['*' => Http::response(['data' => [[
        'composer_name' => 'vendor/licensed', 'slug' => 'licensed', 'is_paid' => true,
        'product_tier' => 'premium', 'install_state' => 'purchase_required',
        'install_eligibility' => ['state' => 'purchase_required', 'can_install' => false],
        'purchase_url' => 'https://capell.app/marketplace/licensed',
    ]]])]);
    $package = GetPluginsAction::run('download')->get('vendor/licensed');
    expect($package)->toBeInstanceOf(PackageData::class)
        ->and($package?->isPaid)->toBeTrue()
        ->and($package?->slug)->toBe('licensed')
        ->and($package?->installEligibility)->toBe(['state' => 'purchase_required', 'can_install' => false])
        ->and($package?->purchaseUrl)->toBe('https://capell.app/marketplace/licensed');
});

it('checks the chosen site domain through the existing entitlement contract', function (): void {
    $decision = ExtensionLicenceDecisionData::fromApiResponse(['licence_status' => 'active', 'can_download' => true, 'can_install' => true]);
    $entitlements = Mockery::mock(ExtensionEntitlements::class);
    $entitlements->shouldReceive('licenceDecision')->andReturnUsing(
        static fn (string $slug, string $operation, string $domain): ?ExtensionLicenceDecisionData => $slug === 'licensed' && $operation === 'install' && $domain === 'example.test' ? $decision : null,
    );
    $package = new PackageData(name: 'vendor/licensed', type: PackageTypeEnum::Plugin, slug: 'licensed', isPaid: true);
    app()->instance(ExtensionEntitlements::class, $entitlements);
    expect(CheckInstallPackageAccessAction::run($package, 'https://example.test:8443/blog'))->toBe($decision);
});

it('keeps unverified account access distinct from a licence denial', function (): void {
    $entitlements = Mockery::mock(ExtensionEntitlements::class);
    $entitlements->shouldReceive('licenceDecision')->andThrow(new RuntimeException('Account unavailable'));
    $package = new PackageData(name: 'vendor/licensed', type: PackageTypeEnum::Plugin, slug: 'licensed', isPaid: true);
    app()->instance(ExtensionEntitlements::class, $entitlements);
    expect(CheckInstallPackageAccessAction::run($package, 'https://example.test'))->toBeNull();
    Http::fake();
    app()->instance(ExtensionEntitlements::class, new NullExtensionEntitlements);
    expect(CheckInstallPackageAccessAction::run($package, 'https://example.test'))->toBeNull();
    Http::assertNothingSent();
});
