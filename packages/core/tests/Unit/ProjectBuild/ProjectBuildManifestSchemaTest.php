<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Actions\ProjectBuild\ReadProjectBuildManifestAction;
use Capell\Core\Actions\ProjectBuild\VerifyProjectBuildManifestSignatureAction;
use Capell\Core\Data\ProjectBuild\ProjectBuildPackageData;
use Capell\Core\Data\ProjectBuild\ProjectBuildRouteData;
use Capell\Core\Support\ProjectBuild\ProjectBuildArtifactHandlerRegistry;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestConstraints;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestMigrationRegistry;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestSchema;

it('publishes a closed draft 2020-12 schema for the portable envelope', function (): void {
    $schema = ProjectBuildManifestSchema::toArray();

    expect($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema['$id'])->toBe('https://schemas.capell.app/project-build-manifest/v1.json')
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toContain('siteSpec', 'artifacts', 'packages', 'sites', 'routes', 'compatibility', 'signature')
        ->and($schema['$defs']['artifact']['additionalProperties'])->toBeFalse()
        ->and($schema['$defs']['siteSpecArtifact']['properties']['type']['const'])->toBe('site-spec')
        ->and($schema['$defs']['siteSpecArtifact']['properties']['schemaVersion']['const'])->toBe(ProjectBuildManifestConstraints::CURRENT_SITE_SPEC_SCHEMA_VERSION)
        ->and($schema['$defs']['artifact']['properties']['digest']['pattern'])->toBe(ProjectBuildManifestConstraints::DIGEST_PATTERN)
        ->and($schema['$defs']['artifact']['properties']['path']['maxLength'])->toBe(ProjectBuildManifestConstraints::MAX_ARTIFACT_PATH_LENGTH)
        ->and($schema['$defs']['route']['properties']['path']['maxLength'])->toBe(ProjectBuildManifestConstraints::MAX_ROUTE_PATH_LENGTH)
        ->and($schema['$defs']['site']['properties']['locales']['maxItems'])->toBe(ProjectBuildManifestConstraints::MAX_LOCALES_PER_SITE)
        ->and($schema['$defs']['compatibility']['properties']['capell']['minLength'])->toBe(1)
        ->and($schema['$defs']['compatibility']['properties']['php']['minLength'])->toBe(1)
        ->and($schema['$defs']['signature']['properties']['value']['minLength'])->toBe(88)
        ->and($schema['$defs']['signature']['properties']['value']['maxLength'])->toBe(88)
        ->and($schema['$defs']['signature']['properties']['value']['pattern'])->toBe(ProjectBuildManifestConstraints::SIGNATURE_PATTERN);
});

it('verifies canonical signed fixtures with real SiteSpec bytes and future topology', function (string $fixture, int $siteCount, int $routeCount, string $signingDigest): void {
    $json = file_get_contents(dirname(__DIR__, 2) . '/fixtures/project-build/' . $fixture);
    expect($json)->toBeString();
    assert(is_string($json));

    $manifest = (new ReadProjectBuildManifestAction(new ProjectBuildManifestMigrationRegistry))->handle($json);
    $siteSpecBytes = file_get_contents(dirname(__DIR__, 2) . '/fixtures/project-build/' . $manifest->siteSpec->path);
    $publicKey = base64_decode(trim((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/project-build/signing-public-key.txt')), true);
    expect($siteSpecBytes)->toBeString()
        ->and($publicKey)->toBeString();
    assert(is_string($siteSpecBytes));
    assert(is_string($publicKey));

    resolve(ProjectBuildArtifactHandlerRegistry::class)->validate($manifest->siteSpec->artifactReference(), $siteSpecBytes);
    VerifyProjectBuildManifestSignatureAction::run($manifest, $publicKey);
    $siteSpec = json_decode($siteSpecBytes, true, 512, JSON_THROW_ON_ERROR);

    expect($manifest->sites)->toHaveCount($siteCount)
        ->and($manifest->routes)->toHaveCount($routeCount)
        ->and($manifest->sites[0]->defaultLocale)->toBe('en-GB')
        ->and($manifest->packages[0]->name)->toBe('capell-app/navigation')
        ->and(hash('sha256', $siteSpecBytes))->toBe($manifest->siteSpec->digest)
        ->and(hash('sha256', CanonicalizeProjectBuildManifestSigningInputAction::run($manifest)))->toBe($signingDigest)
        ->and(data_get($siteSpec, 'extensions'))->toBe(['capell-app/navigation'])
        ->and(data_get($siteSpec, 'navigations.0.pageSlugs'))->toBe(['home']);
})->with([
    'initial launch' => ['one-site-one-locale.json', 1, 1, '6113ad0ddab4b8abaf928d800692b0bf9ac71db16af19404841a09a5bca879a0'],
    'future topology' => ['two-site-two-locale.json', 2, 4, '0d3c1f7130b2c6f86b24363cf210ace27a8f04d99745ca4fdb6db8fefbf4f4f7'],
]);

it('pins the unsupported future topology fallback and extension contract for shared consumers', function (): void {
    $manifestJson = file_get_contents(dirname(__DIR__, 2) . '/fixtures/project-build/two-site-two-locale.json');
    $expectationsJson = file_get_contents(dirname(__DIR__, 2) . '/fixtures/project-build/two-site-two-locale.expectations.json');
    assert(is_string($manifestJson));
    assert(is_string($expectationsJson));
    $manifest = (new ReadProjectBuildManifestAction(new ProjectBuildManifestMigrationRegistry))->handle($manifestJson);
    $expectations = json_decode($expectationsJson, true, 512, JSON_THROW_ON_ERROR);
    $siteSpec = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/project-build/' . $manifest->siteSpec->path), true, 512, JSON_THROW_ON_ERROR);
    $fallbacks = [];

    foreach ($manifest->sites as $site) {
        foreach ($site->locales as $locale) {
            if ($locale !== $site->defaultLocale) {
                $fallbacks[$site->key . ':' . $locale] = $site->defaultLocale;
            }
        }
    }

    expect($expectations['launchSupported'])->toBeFalse()
        ->and(array_map(static fn (ProjectBuildRouteData $route): array => $route->toArray(), $manifest->routes))->toBe($expectations['routes'])
        ->and($fallbacks)->toBe($expectations['fallbacks'])
        ->and(array_map(static fn (ProjectBuildPackageData $package): string => $package->name, $manifest->packages))->toBe($expectations['extensionResults']['packages'])
        ->and(data_get($siteSpec, 'extensions'))->toBe($expectations['extensionResults']['siteSpecExtensions'])
        ->and(data_get($siteSpec, 'navigations.0.key'))->toBe($expectations['extensionResults']['navigationKey'])
        ->and(data_get($siteSpec, 'navigations.0.pageSlugs'))->toBe($expectations['extensionResults']['pageSlugs']);
});
