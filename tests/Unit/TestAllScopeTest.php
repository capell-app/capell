<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2) . '/scripts/test-all/TestAllMatrix.php';
require_once dirname(__DIR__, 2) . '/scripts/test-all/TestAllScope.php';

$requiredBy = [
    'core' => ['admin', 'frontend', 'installer', 'marketplace'],
    'admin' => ['marketplace'],
    'frontend' => [],
    'installer' => [],
    'marketplace' => [],
];

it('reads which packages require which from the package manifests', function (): void {
    $actual = TestAllScope::requiredBy(dirname(__DIR__, 2));

    expect($actual['core'])->toContain('admin', 'frontend', 'installer', 'marketplace')
        ->and($actual['admin'])->toContain('marketplace')
        ->and($actual['marketplace'])->toBe([]);
});

it('narrows a leaf package change to that package and the packages that require it', function () use ($requiredBy): void {
    expect(TestAllScope::packages(['packages/frontend/src/A.php', 'packages/frontend/tests/ATest.php'], $requiredBy))->toBe(['frontend'])
        ->and(TestAllScope::packages(['packages/admin/src/A.php'], $requiredBy))->toBe(['admin', 'marketplace'])
        ->and(TestAllScope::packages(['packages/admin/src/A.php', 'packages/installer/src/B.php'], $requiredBy))->toBe(['admin', 'installer', 'marketplace']);
});

it('selects the whole matrix when the change cannot be confined to leaf packages', function (string $file) use ($requiredBy): void {
    expect(TestAllScope::packages(['packages/frontend/src/A.php', $file], $requiredBy))->toBeNull();
})->with([
    'core' => 'packages/core/src/A.php',
    'a new package' => 'packages/newpackage/src/A.php',
    'the root manifest' => 'composer.json',
    'shared tests' => 'tests/Support/OwnedApplicationPaths.php',
    'scripts' => 'scripts/test-all/TestAllMatrix.php',
    'the workflow' => '.github/workflows/test-full.yml',
]);

it('selects the whole matrix when no changed file can be listed', function () use ($requiredBy): void {
    expect(TestAllScope::packages([], $requiredBy))->toBeNull()
        ->and(TestAllScope::changedFiles(dirname(__DIR__, 2), 'not-a-revision', 'HEAD'))->toBe([]);
});

it('keeps sentinel, architecture, integration and portability cells and drops only unaffected package cells', function () use ($requiredBy): void {
    $cells = [...TestAllMatrix::sentinel(), ...TestAllMatrix::behaviour(), ...TestAllMatrix::unit(), ...TestAllMatrix::portability()];
    $ids = array_column(TestAllScope::filter($cells, ['frontend'], array_keys($requiredBy)), 'id');

    expect($ids)->toContain('sentinel-unit', 'sentinel-database', 'l13-arch', 'l13-integration', 'l13-feature-frontend', 'l13-unit-frontend', 'l13-portability-sqlite')
        ->not->toContain('l13-feature-core')
        ->not->toContain('l13-feature-admin-shard-1')
        ->not->toContain('l13-unit-installer')
        ->not->toContain('l13-feature-marketplace')
        ->and(TestAllScope::filter($cells, null, array_keys($requiredBy)))->toBe($cells);
});

it('scopes the matrix from a real diff and falls back to the full matrix on an unknown revision', function (): void {
    $root = dirname(__DIR__, 2);
    $run = static function (string ...$arguments) use ($root): array {
        $process = new Process([PHP_BINARY, 'scripts/test-all-matrix.php', 'behaviour', ...$arguments], $root);
        $process->mustRun();

        return array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['include'], 'id');
    };

    expect($run('--changed-from=not-a-revision', '--changed-to=HEAD'))->toBe($run());
});
