<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\ValidateProjectBuildManifestBundleAction;
use Capell\Core\Support\ProjectBuild\ProjectBuildArtifactHandlerRegistry;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2) . '/scripts/check-stable-extension-api.php';

it('keeps the active public-release baseline current', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/check-stable-extension-api.php', '--check'], $root);
    $process->run();

    $output = $process->getOutput();

    expect($process->getExitCode())->toBe(0, trim($process->getErrorOutput()))
        ->and(
            str_contains($output, 'baseline is current')
                || str_contains($output, 'explicit compatibility decision'),
        )->toBeTrue()
        ->and(json_decode((string) file_get_contents($root . '/docs/packages/stable-extension-api-baseline.json'), true, flags: JSON_THROW_ON_ERROR)['status'])
        ->toBe('active')
        ->and((string) file_get_contents($root . '/scripts/check-stable-extension-api.php'))
        ->not->toContain('pending-first-public-release');
});

it('hashes only the declared action entrypoint and excludes dependency trait methods and constructors', function (): void {
    $identifier = ValidateProjectBuildManifestBundleAction::class;
    $reflection = new ReflectionMethod($identifier, 'handle');
    $parameters = array_map(static fn (ReflectionParameter $parameter): string => sprintf(
        '%s%s:%s',
        $parameter->isOptional() ? '?' : '',
        $parameter->getName(),
        capellStableApiType($parameter->getType(), $reflection->getDeclaringClass()),
    ), $reflection->getParameters());
    $expected = hash('sha256', 'handle(' . implode(',', $parameters) . '):' . capellStableApiType($reflection->getReturnType(), $reflection->getDeclaringClass()));

    expect(capellStableApiSignature($identifier))->toBe($expected);
});

it('excludes registry dependency injection constructors while retaining declared operations', function (): void {
    $identifier = ProjectBuildArtifactHandlerRegistry::class;
    $reflection = new ReflectionClass($identifier);
    $methods = [];

    foreach (['register', 'types', 'validate'] as $methodName) {
        $method = $reflection->getMethod($methodName);
        $parameters = array_map(static fn (ReflectionParameter $parameter): string => sprintf(
            '%s%s:%s',
            $parameter->isOptional() ? '?' : '',
            $parameter->getName(),
            capellStableApiType($parameter->getType(), $method->getDeclaringClass()),
        ), $method->getParameters());
        $methods[] = $method->getName() . '(' . implode(',', $parameters) . '):' . capellStableApiType($method->getReturnType(), $method->getDeclaringClass());
    }

    sort($methods);

    expect(capellStableApiSignature($identifier))->toBe(hash('sha256', implode('|', $methods)));
});

it('classifies every compatibility-relevant form of stable drift', function (): void {
    $baseline = [
        'surfaces' => [
            'stable.removed' => ['signature' => 'a'],
            'stable.changed' => ['signature' => 'before'],
        ],
        'manifestRequirements' => ['name'],
        'packageConstraints' => ['php' => '^8.4'],
        'migrations' => ['create_records.php'],
        'configKeys' => ['capell.stable'],
    ];
    $current = [
        'surfaces' => ['stable.changed' => ['signature' => 'after']],
        'manifestRequirements' => ['name', 'providers'],
        'packageConstraints' => ['php' => '^8.5'],
        'migrations' => [],
        'configKeys' => ['capell.renamed'],
    ];

    expect(capellStableApiDrift($baseline, $current))->toBe([
        'removed class: stable.removed',
        'changed public signature: stable.changed',
        'manifestRequirements',
        'packageConstraints',
        'migrations',
        'configKeys',
    ]);
});

it('keeps experimental package extension seams out of the stable baseline', function (): void {
    $surfaces = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/docs/packages/stable-extension-api-baseline.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['surfaces'];

    expect($surfaces)->not->toHaveKeys([
        'admin.contract.admin-tool-item',
        'admin.tag.admin-tool-item',
        'core.schema.project-build-manifest-v1',
        'frontend.dto.package-dependency',
        'frontend.enum.package-dependency-type',
        'frontend.registry.package-dependency',
    ]);
});

it('includes the complete typed closure of stable project build actions', function (): void {
    $surfaces = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/docs/packages/stable-extension-api-baseline.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['surfaces'];

    expect($surfaces)->toHaveKeys([
        'core.dto.project-build-artifact-reference',
        'core.dto.project-build-compatibility',
        'core.dto.project-build-manifest',
        'core.dto.project-build-package',
        'core.dto.project-build-route',
        'core.dto.project-build-signature',
        'core.dto.project-build-site',
        'core.dto.project-build-site-spec-reference',
        'core.registry.project-build-artifact-handler',
    ])->not->toHaveKey('core.schema.project-build-manifest-v1');
});

it('keeps the approved project build producer actions in the stable baseline', function (): void {
    $surfaces = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/docs/packages/stable-extension-api-baseline.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['surfaces'];

    expect($surfaces)->toHaveKeys([
        'core.action.project-build-signing-input',
        'core.action.validate-project-build-bundle',
        'core.action.verify-project-build-signature',
    ])
        ->and($surfaces['core.action.project-build-signing-input']['contractTestId'])->toBe('core.project-build-manifest-signing')
        ->and($surfaces['core.action.validate-project-build-bundle']['contractTestId'])->toBe('core.project-build-manifest-bundle')
        ->and($surfaces['core.action.verify-project-build-signature']['contractTestId'])->toBe('core.project-build-manifest-signing');
});
