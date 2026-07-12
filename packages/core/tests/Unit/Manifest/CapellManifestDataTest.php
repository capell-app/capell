<?php

declare(strict_types=1);

use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;

it('hydrates from a valid manifest v3 array', function (): void {
    $manifest = CapellManifestData::fromArray(manifestV3DataFixture());

    expect($manifest->manifestVersion)->toBe(3)
        ->and($manifest->name)->toBe('vendor/package')
        ->and($manifest->slug)->toBe('package')
        ->and($manifest->displayName)->toBe('Package')
        ->and($manifest->kind)->toBe('package')
        ->and($manifest->capellApiVersion)->toBe('^1.0')
        ->and($manifest->productGroup)->toBe('Tests')
        ->and($manifest->tier)->toBe('free')
        ->and($manifest->demo)->toBeFalse()
        ->and($manifest->surfaces)->toBe(['admin'])
        ->and($manifest->requires)->toBe([])
        ->and($manifest->providers->runtime)->toBe([])
        ->and($manifest->performance->cacheSafety->cacheable)->toBeFalse()
        ->and($manifest->commercial->proposedLicense)->toBe('free')
        ->and($manifest->marketplaceCategories)->toBe(['tests']);
});

it('hydrates the documentation url from the manifest', function (): void {
    $data = manifestV3DataFixture();
    $data['documentationUrl'] = 'https://docs.capell.app/packages/package';

    $manifest = CapellManifestData::fromArray($data);

    expect($manifest->documentationUrl)->toBe('https://docs.capell.app/packages/package')
        ->and($manifest->toArray())->toHaveKey('documentationUrl', 'https://docs.capell.app/packages/package');
});

it('falls back to the provided documentation url when the manifest omits one', function (): void {
    $manifest = CapellManifestData::fromArray(
        manifestV3DataFixture(),
        null,
        'https://docs.capell.app/packages/package',
    );

    expect($manifest->documentationUrl)->toBe('https://docs.capell.app/packages/package');
});

it('prefers the manifest documentation url over the fallback', function (): void {
    $data = manifestV3DataFixture();
    $data['documentationUrl'] = 'https://docs.capell.app/packages/explicit';

    $manifest = CapellManifestData::fromArray($data, null, 'https://docs.capell.app/packages/fallback');

    expect($manifest->documentationUrl)->toBe('https://docs.capell.app/packages/explicit');
});

it('treats a blank documentation url as absent', function (): void {
    $data = manifestV3DataFixture();
    $data['documentationUrl'] = '   ';

    $manifest = CapellManifestData::fromArray($data);

    expect($manifest->documentationUrl)->toBeNull()
        ->and($manifest->toArray())->not->toHaveKey('documentationUrl');
});

it('round-trips demo package metadata', function (): void {
    $data = manifestV3DataFixture();
    $data['demo'] = true;

    $manifest = CapellManifestData::fromArray($data);

    expect($manifest->demo)->toBeTrue()
        ->and($manifest->toArray())->toHaveKey('demo', true);
});

it('round-trips marketplace hidden metadata', function (): void {
    $data = manifestV3DataFixture();
    $data['marketplace']['hidden'] = true;

    $manifest = CapellManifestData::fromArray($data);

    expect($manifest->marketplaceHidden)->toBeTrue()
        ->and($manifest->toArray()['marketplace'])->toHaveKey('hidden', true);
});

it('round-trips package security metadata', function (): void {
    $data = manifestV3DataFixture();
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
        ],
        'externalHttpClients' => [
            'requiresTimeouts' => true,
        ],
    ];

    $manifest = CapellManifestData::fromArray($data);

    expect($manifest->security?->riskTier)->toBe('sensitive')
        ->and($manifest->security?->publicSurface)->toBe([
            'routeNames' => ['capell-example.webhook'],
            'auth' => 'public',
        ])
        ->and($manifest->security?->publicOutput)->toBe([
            'cacheSafe' => true,
            'forbidAuthoringSurface' => true,
            'forbidSecrets' => true,
        ])
        ->and($manifest->toArray())->toHaveKey('security')
        ->and(CapellManifestData::fromArray($manifest->toArray())->toArray())->toBe($manifest->toArray());
});

it('rejects legacy manifest v2 arrays during hydration', function (): void {
    expect(fn (): CapellManifestData => CapellManifestData::fromArray([
        'manifest-version' => 2,
        'name' => 'vendor/package',
        'kind' => 'package',
        'capell-version' => '^1.0',
    ]))->toThrow(InvalidManifestException::class, 'manifest-version 3');
});

it('round-trips through toArray and fromArray without legacy capell-version', function (): void {
    $manifest = CapellManifestData::fromArray(manifestV3DataFixture());
    $array = $manifest->toArray();

    expect($array['manifest-version'])->toBe(3)
        ->and($array)->toHaveKey('capellApiVersion', '^1.0')
        ->and($array)->not->toHaveKey('capell-version')
        ->and(CapellManifestData::fromArray($array)->toArray())->toBe($array);
});

it('resolves namespace from explicit field or provider classes', function (): void {
    $explicit = manifestV3DataFixture();
    $explicit['namespace'] = 'Vendor\\Package';

    $provider = manifestV3DataFixture();
    $provider['providers']['runtime'] = ['Vendor\\Package\\Providers\\PackageServiceProvider'];

    expect(CapellManifestData::fromArray($explicit)->resolvedNamespace())->toBe('Vendor\\Package')
        ->and(CapellManifestData::fromArray($provider)->resolvedNamespace())->toBe('Vendor\\Package');
});

function manifestV3DataFixture(): array
{
    return capellManifestV3Array();
}
