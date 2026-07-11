<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\FilterExtensionOperationPackagesAction;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;

it('filters extension operation packages by search terms tabs and product groups', function (): void {
    $packages = [
        extensionOperationPackageForFilterTest(
            packageName: 'capell-app/core',
            label: 'Core CMS',
            description: 'Primary runtime package',
            updateAvailable: true,
            installed: true,
            available: false,
            core: true,
            tier: 'free',
            certification: 'verified',
            runtimeStatus: 'healthy',
            healthState: 'green',
            productGroup: 'Core',
        ),
        extensionOperationPackageForFilterTest(
            packageName: 'vendor/premium-forms',
            label: 'Premium Forms',
            description: 'Advanced security forms',
            latestVersion: '2.0.0',
            installed: false,
            available: true,
            core: false,
            tier: 'premium',
            certification: 'trusted',
            runtimeStatus: 'blocked',
            healthState: 'red',
            blocked: true,
            needsAttention: true,
            productGroup: 'Commerce',
        ),
        extensionOperationPackageForFilterTest(
            packageName: 'vendor/blog',
            label: 'Blog',
            description: null,
            installed: true,
            available: true,
            core: false,
            tier: 'free',
            certification: 'community',
            runtimeStatus: 'degraded',
            healthState: 'amber',
            needsAttention: true,
            productGroup: 'Content',
        ),
    ];

    $action = new FilterExtensionOperationPackagesAction;

    expect(extensionOperationPackageNames($action->handle($packages, search: 'security premium')))->toBe([
        'vendor/premium-forms',
    ])
        ->and(extensionOperationPackageNames($action->handle($packages, tab: 'needs_attention')))->toBe([
            'vendor/premium-forms',
            'vendor/blog',
        ])
        ->and(extensionOperationPackageNames($action->handle($packages, tab: 'blocked')))->toBe([
            'vendor/premium-forms',
        ])
        ->and(extensionOperationPackageNames($action->handle($packages, tab: 'updates')))->toBe([
            'capell-app/core',
        ])
        ->and(extensionOperationPackageNames($action->handle($packages, tab: 'premium')))->toBe([
            'vendor/premium-forms',
        ])
        ->and(extensionOperationPackageNames($action->handle($packages, tab: 'installed')))->toBe([
            'capell-app/core',
            'vendor/blog',
        ])
        ->and(extensionOperationPackageNames($action->handle($packages, tab: 'available')))->toBe([
            'vendor/premium-forms',
            'vendor/blog',
        ])
        ->and(extensionOperationPackageNames($action->handle($packages, tab: 'core')))->toBe([
            'capell-app/core',
        ])
        ->and(extensionOperationPackageNames($action->handle($packages, tab: 'addons')))->toBe([
            'vendor/premium-forms',
            'vendor/blog',
        ])
        ->and(extensionOperationPackageNames($action->handle($packages, tab: 'unknown', productGroup: 'Commerce')))->toBe([
            'vendor/premium-forms',
        ]);
});

function extensionOperationPackageForFilterTest(
    string $packageName,
    string $label,
    ?string $description = 'Package description',
    ?string $version = '1.0.0',
    ?string $latestVersion = null,
    bool $updateAvailable = false,
    bool $installed = true,
    bool $available = true,
    bool $core = false,
    string $tier = 'free',
    string $certification = 'community',
    string $runtimeStatus = 'healthy',
    string $healthState = 'green',
    bool $blocked = false,
    bool $needsAttention = false,
    string $productGroup = 'Other',
): ExtensionOperationPackageData {
    return new ExtensionOperationPackageData(
        packageName: $packageName,
        label: $label,
        description: $description,
        version: $version,
        latestVersion: $latestVersion,
        imageUrl: null,
        imageUrls: [],
        updateAvailable: $updateAvailable,
        installed: $installed,
        enabled: true,
        available: $available,
        core: $core,
        visibility: 'public',
        canUninstall: $installed,
        tier: $tier,
        certification: $certification,
        runtimeStatus: $runtimeStatus,
        runtimeAllowed: ! $blocked,
        healthState: $healthState,
        healthAlerts: [],
        missingRequiredTables: [],
        contributionCount: 0,
        managementEntries: [],
        premiumMissingMarketplaceAccount: false,
        blocked: $blocked,
        needsAttention: $needsAttention,
        riskScore: $blocked ? 100 : 0,
        productGroup: $productGroup,
    );
}

/**
 * @param  list<ExtensionOperationPackageData>  $packages
 * @return list<string>
 */
function extensionOperationPackageNames(array $packages): array
{
    return array_map(
        fn (ExtensionOperationPackageData $package): string => $package->packageName,
        $packages,
    );
}
