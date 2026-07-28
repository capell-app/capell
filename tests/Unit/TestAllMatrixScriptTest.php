<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/test-all/TestAllMatrix.php';

it('defines the complete Laravel 12 and 13 Test All matrix once', function (): void {
    $sentinel = TestAllMatrix::sentinel();
    $behaviour = TestAllMatrix::behaviour();
    $unit = TestAllMatrix::unit();

    expect($sentinel)
        ->toHaveCount(2)
        ->and(array_column($sentinel, 'id'))
        ->toBe(['sentinel-unit', 'sentinel-database'])
        ->and(array_column($sentinel, 'database'))
        ->toBe(['sqlite', 'mysql'])
        ->and($behaviour)
        ->toHaveCount(12)
        ->and($unit)
        ->toHaveCount(10);

    foreach (['12.*' => '10.*', '13.*' => '11.*'] as $laravel => $testbench) {
        $frameworkBehaviour = array_values(array_filter(
            $behaviour,
            static fn (array $cell): bool => $cell['laravel'] === $laravel,
        ));
        $frameworkUnit = array_values(array_filter(
            $unit,
            static fn (array $cell): bool => $cell['laravel'] === $laravel,
        ));

        expect($frameworkBehaviour)->toHaveCount(6);
        expect(array_column($frameworkBehaviour, 'testbench'))->each->toBe($testbench);
        expect(array_column($frameworkBehaviour, 'test_suite'))
            ->toBe(['Feature', 'Feature', 'Feature', 'Feature', 'Feature', 'Integration'])
            ->and(array_column($frameworkBehaviour, 'package'))
            ->toBe(['Core', 'Admin', 'Frontend', 'Installer', 'Marketplace', 'All'])
            ->and(array_column($frameworkBehaviour, 'database'))
            ->each->toBe('mysql');

        expect($frameworkUnit)->toHaveCount(5);
        expect(array_column($frameworkUnit, 'testbench'))->each->toBe($testbench);
        expect(array_column($frameworkUnit, 'package'))
            ->toBe(['Core', 'Admin', 'Frontend', 'Installer', 'Marketplace'])
            ->and(array_column($frameworkUnit, 'database'))
            ->each->toBe('sqlite');
    }
});

it('keeps CI and the local fallback on the same matrix, dependency, and cell scripts', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = (string) file_get_contents($root . '/.github/workflows/test-full.yml');
    $localRunner = (string) file_get_contents($root . '/scripts/run-test-all-matrix.php');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($workflow)
        ->toContain('php scripts/test-all-matrix.php target --cell="$TARGET_CELL"')
        ->toContain('scripts/test-all-matrix.php behaviour')
        ->toContain('scripts/test-all-matrix.php unit')
        ->toContain('scripts/prepare-test-all-dependencies.php')
        ->toContain('scripts/run-test-all-cell.php')
        ->and($localRunner)
        ->toContain('TestAllMatrix::all()')
        ->toContain('scripts/prepare-test-all-dependencies.php')
        ->toContain('scripts/run-test-all-cell.php')
        ->toContain("'git', 'worktree', 'add', '--detach'")
        ->toContain("'git', 'worktree', 'remove', '--force'")
        ->toMatch("/'docker',\s*'run'/")
        ->toContain('/summary.json')
        ->not->toContain('Illuminate\\Support\\Facades')
        ->and($composer['scripts']['test:all:matrix:local'] ?? null)
        ->toBe([
            'Composer\\Config::disableProcessTimeout',
            '@php scripts/run-test-all-matrix.php',
        ]);
});

it('can select one exact hosted repair cell without changing its topology', function (): void {
    $root = dirname(__DIR__, 2);
    $command = sprintf(
        'php %s target --cell=l12-feature-admin',
        escapeshellarg($root . '/scripts/test-all-matrix.php'),
    );
    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0);

    $matrix = json_decode(implode(PHP_EOL, $output), true, flags: JSON_THROW_ON_ERROR);

    expect($matrix['include'])->toHaveCount(1)
        ->and($matrix['include'][0]['id'])->toBe('l12-feature-admin')
        ->and($matrix['include'][0]['database'])->toBe('mysql')
        ->and($matrix['include'][0]['test_suite'])->toBe('Feature')
        ->and($matrix['include'][0]['package'])->toBe('Admin');
});

it('rejects sentinel cells as targeted hosted repairs', function (string $cell): void {
    $root = dirname(__DIR__, 2);
    $command = sprintf(
        'php %s target --cell=%s 2>&1',
        escapeshellarg($root . '/scripts/test-all-matrix.php'),
        escapeshellarg($cell),
    );
    exec($command, $output, $exitCode);

    expect($exitCode)->not->toBe(0)
        ->and(implode(PHP_EOL, $output))
        ->toContain(sprintf('Test All cell [%s] cannot be dispatched as a targeted hosted repair cell.', $cell));
})->with([
    'sentinel unit' => 'sentinel-unit',
    'sentinel database' => 'sentinel-database',
]);
