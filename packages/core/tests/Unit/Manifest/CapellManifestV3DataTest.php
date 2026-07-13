<?php

declare(strict_types=1);

use Capell\Core\Data\Manifest\ExtensionCacheSafetyData;
use Capell\Core\Data\Manifest\ExtensionCommercialData;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\Manifest\ExtensionHealthCheckData;
use Capell\Core\Data\Manifest\ExtensionPerformanceBudgetData;
use Capell\Core\Data\Manifest\ExtensionProviderData;
use Capell\Core\Data\Manifest\ExtensionScreenshotData;
use Capell\Core\Data\Manifest\ExtensionSecurityData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\ExtensionManifestVersion;
use Capell\Core\Support\Manifest\CapellManifestData;

if (! function_exists('manifestV3Fixture')) {
    function manifestV3Fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../fixtures/manifest-v3/' . $name . '.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}

it('hydrates manifest v3 fields into immutable data objects', function (): void {
    $manifest = CapellManifestData::fromArray(
        manifestV3Fixture('valid-premium-package'),
        '/tmp/example',
    );

    expect($manifest->manifestVersion)->toBe(ExtensionManifestVersion::V3->value)
        ->and($manifest->name)->toBe('capell-app/example')
        ->and($manifest->slug)->toBe('example')
        ->and($manifest->displayName)->toBe('Example')
        ->and($manifest->capellApiVersion)->toBe('^1.0')
        ->and($manifest->version)->toBe('1.x-dev')
        ->and($manifest->productGroup)->toBe('Developer Tools')
        ->and($manifest->tier)->toBe('premium')
        ->and($manifest->bundle)->toBe('platform')
        ->and($manifest->providers)->toBeInstanceOf(ExtensionProviderData::class)
        ->and($manifest->contributes[0])->toBeInstanceOf(ExtensionContributionData::class)
        ->and($manifest->contributes[0]->type)->toBe(ExtensionContributionType::AdminPage)
        ->and($manifest->performance)->toBeInstanceOf(ExtensionPerformanceBudgetData::class)
        ->and($manifest->performance->cacheSafety)->toBeInstanceOf(ExtensionCacheSafetyData::class)
        ->and($manifest->security)->toBeNull()
        ->and($manifest->healthChecks[0])->toBeInstanceOf(ExtensionHealthCheckData::class)
        ->and($manifest->commercial)->toBeInstanceOf(ExtensionCommercialData::class)
        ->and($manifest->commercial->proposedLicense)->toBe('free')
        ->and($manifest->commercial->requestedCertification)->toBe('first-party')
        ->and($manifest->commercial->privateDocsRequested)->toBeTrue()
        ->and($manifest->marketplaceScreenshots[0])->toBeInstanceOf(ExtensionScreenshotData::class)
        ->and($manifest->installPath)->toBe('/tmp/example');
});

it('round-trips manifest v3 through array form without commercial runtime truth', function (): void {
    $manifest = CapellManifestData::fromArray(manifestV3Fixture('valid-premium-package'));
    $array = $manifest->toArray();

    expect($array['manifest-version'])->toBe(3)
        ->and($array)->toHaveKeys([
            'slug',
            'displayName',
            'capellApiVersion',
            'version',
            'product',
            'contributes',
            'performance',
            'commercial',
            'marketplace',
        ])
        ->and($array)->not->toHaveKey('capell-version')
        ->and($array['commercial'])->toHaveKeys([
            'proposedLicense',
            'requestedCertification',
            'supportPolicy',
            'privateDocsRequested',
        ])
        ->and($array['commercial'])->not->toHaveKeys([
            'effectiveLicense',
            'certificationStatus',
            'license',
        ])
        ->and($array['providers'])->toHaveKeys([
            'metadata',
            'install',
            'runtime',
            'auth',
            'admin',
            'frontend',
        ]);
});

it('round-trips manifest v3 security metadata', function (): void {
    $data = manifestV3Fixture('valid-premium-package');
    $data['security'] = [
        'riskTier' => 'sensitive',
        'publicSurface' => [
            'routeNames' => ['capell-example.webhook'],
            'auth' => 'public',
        ],
        'publicOutput' => [
            'cacheSafe' => true,
            'forbidAuthoringSurface' => true,
            'forbidSecrets' => true,
            'forbidPublicBladeQueries' => true,
        ],
    ];

    $manifest = CapellManifestData::fromArray($data);

    expect($manifest->security)->toBeInstanceOf(ExtensionSecurityData::class)
        ->and($manifest->security?->riskTier)->toBe('sensitive')
        ->and($manifest->toArray())->toHaveKey('security')
        ->and(CapellManifestData::fromArray($manifest->toArray())->toArray()['security'])->toBe($manifest->toArray()['security']);
});

it('round-trips optional performance delivery metadata', function (): void {
    $data = manifestV3Fixture('valid-premium-package');
    $data['performance']['cssSizeBudgetBytes'] = 24576;
    $data['performance']['jsSizeBudgetBytes'] = 49152;
    $data['performance']['requiresLivewire'] = true;
    $data['performance']['cacheabilityProfile'] = 'layout-static';
    $data['performance']['criticalCssEligible'] = true;
    $data['performance']['publicQueryRisk'] = false;

    $manifest = CapellManifestData::fromArray($data);

    expect($manifest->performance->cssSizeBudgetBytes)->toBe(24576)
        ->and($manifest->performance->jsSizeBudgetBytes)->toBe(49152)
        ->and($manifest->performance->requiresLivewire)->toBeTrue()
        ->and($manifest->performance->cacheabilityProfile)->toBe('layout-static')
        ->and($manifest->performance->criticalCssEligible)->toBeTrue()
        ->and($manifest->performance->publicQueryRisk)->toBeFalse()
        ->and(CapellManifestData::fromArray($manifest->toArray())->toArray()['performance'])->toBe($manifest->toArray()['performance']);
});

it('normalizes invalid optional performance delivery metadata', function (): void {
    $data = manifestV3Fixture('valid-premium-package');
    $data['performance']['cssSizeBudgetBytes'] = 0;
    $data['performance']['jsSizeBudgetBytes'] = -10;
    $data['performance']['requiresLivewire'] = 'yes';
    $data['performance']['cacheabilityProfile'] = '';
    $data['performance']['criticalCssEligible'] = 1;
    $data['performance']['publicQueryRisk'] = 'false';

    $manifest = CapellManifestData::fromArray($data);

    expect($manifest->performance->cssSizeBudgetBytes)->toBeNull()
        ->and($manifest->performance->jsSizeBudgetBytes)->toBeNull()
        ->and($manifest->performance->requiresLivewire)->toBeNull()
        ->and($manifest->performance->cacheabilityProfile)->toBeNull()
        ->and($manifest->performance->criticalCssEligible)->toBeNull()
        ->and($manifest->performance->publicQueryRisk)->toBeNull();
});

it('resolves namespace from composer-style providers when no explicit namespace is present', function (): void {
    $manifest = CapellManifestData::fromArray(manifestV3Fixture('valid-premium-package'));

    expect($manifest->resolvedNamespace())->toBe('Vendor\\Example');
});
