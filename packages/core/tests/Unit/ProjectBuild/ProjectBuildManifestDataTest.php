<?php

declare(strict_types=1);

use Capell\Core\Data\ProjectBuild\ProjectBuildArtifactReferenceData;
use Capell\Core\Data\ProjectBuild\ProjectBuildCompatibilityData;
use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Data\ProjectBuild\ProjectBuildPackageData;
use Capell\Core\Data\ProjectBuild\ProjectBuildRouteData;
use Capell\Core\Data\ProjectBuild\ProjectBuildSignatureData;
use Capell\Core\Data\ProjectBuild\ProjectBuildSiteData;

it('represents the complete provider-neutral project build envelope', function (): void {
    $siteSpec = new ProjectBuildArtifactReferenceData(
        key: 'site-spec',
        type: 'site-spec',
        path: 'artifacts/site-spec.json',
        digest: str_repeat('a', 64),
        sizeBytes: 512,
        mediaType: 'application/json',
    );
    $manifest = new ProjectBuildManifestData(
        schemaVersion: 1,
        buildId: '019f7bf4-45b4-70f1-b8c9-f88d8c783b41',
        createdAt: '2026-07-19T12:00:00+00:00',
        siteSpec: $siteSpec,
        artifacts: [new ProjectBuildArtifactReferenceData(
            key: 'theme',
            type: 'capell-theme',
            path: 'artifacts/theme.zip',
            digest: str_repeat('b', 64),
            sizeBytes: 4096,
            mediaType: 'application/zip',
        )],
        packages: [new ProjectBuildPackageData(
            name: 'capell-app/navigation',
            version: '1.0.4',
            releaseIdentity: str_repeat('c', 40),
            installOrder: 10,
        )],
        sites: [new ProjectBuildSiteData(
            key: 'primary',
            defaultLocale: 'en-GB',
            locales: ['en-GB'],
        )],
        routes: [new ProjectBuildRouteData(
            siteKey: 'primary',
            locale: 'en-GB',
            path: '/',
        )],
        compatibility: new ProjectBuildCompatibilityData(
            capell: '^1.0',
            php: '^8.4',
            platforms: ['local', 'laravel-cloud'],
        ),
        signature: new ProjectBuildSignatureData(
            algorithm: 'ed25519',
            keyId: 'capell-build-2026-01',
            value: base64_encode(str_repeat('s', 64)),
        ),
    );

    $payload = $manifest->toArray();

    expect($payload['schemaVersion'])->toBe(1)
        ->and($payload['buildId'])->toBe('019f7bf4-45b4-70f1-b8c9-f88d8c783b41')
        ->and($payload['siteSpec']['key'])->toBe('site-spec')
        ->and($payload['siteSpec']['type'])->toBe('site-spec')
        ->and($payload['artifacts'][0]['key'])->toBe('theme')
        ->and($payload['artifacts'][0]['type'])->toBe('capell-theme')
        ->and($payload['packages'][0]['name'])->toBe('capell-app/navigation')
        ->and($payload['packages'][0]['installOrder'])->toBe(10)
        ->and($payload['sites'][0]['key'])->toBe('primary')
        ->and($payload['sites'][0]['locales'])->toBe(['en-GB'])
        ->and($payload['routes'][0]['siteKey'])->toBe('primary')
        ->and($payload['routes'][0]['path'])->toBe('/')
        ->and($payload['compatibility']['platforms'])->toBe(['local', 'laravel-cloud'])
        ->and($payload['signature']['algorithm'])->toBe('ed25519')
        ->and($payload['signature']['keyId'])->toBe('capell-build-2026-01');
});
