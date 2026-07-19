<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\ReadProjectBuildManifestAction;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestMigrationRegistry;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestSchema;

it('publishes a closed draft 2020-12 schema for the portable envelope', function (): void {
    $schema = ProjectBuildManifestSchema::toArray();

    expect($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema['$id'])->toBe('https://schemas.capell.app/project-build-manifest/v1.json')
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toContain('siteSpec', 'artifacts', 'packages', 'sites', 'routes', 'compatibility', 'signature')
        ->and($schema['$defs']['artifact']['additionalProperties'])->toBeFalse()
        ->and($schema['$defs']['artifact']['properties']['digest']['pattern'])->toBe('^[a-f0-9]{64}$')
        ->and($schema['$defs']['signature']['properties']['value']['minLength'])->toBe(88)
        ->and($schema['$defs']['signature']['properties']['value']['maxLength'])->toBe(88);
});

it('reads canonical one-site and future-compatible multi-site fixtures', function (string $fixture, int $siteCount, int $routeCount): void {
    $json = file_get_contents(dirname(__DIR__, 2) . '/fixtures/project-build/' . $fixture);
    expect($json)->toBeString();
    assert(is_string($json));

    $manifest = (new ReadProjectBuildManifestAction(new ProjectBuildManifestMigrationRegistry(app())))->handle($json);

    expect($manifest->sites)->toHaveCount($siteCount)
        ->and($manifest->routes)->toHaveCount($routeCount);
})->with([
    'initial launch' => ['one-site-one-locale.json', 1, 1],
    'future topology' => ['two-site-two-locale.json', 2, 4],
]);
