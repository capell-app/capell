<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/test-all/TestAllMatrix.php';
require_once dirname(__DIR__, 2) . '/scripts/test-all/TestAllDatabaseService.php';

it('defines the complete Laravel 13 Test All matrix once', function (): void {
    $sentinel = TestAllMatrix::sentinel();
    $behaviour = TestAllMatrix::behaviour();
    $unit = TestAllMatrix::unit();
    $portability = TestAllMatrix::portability();

    expect($sentinel)
        ->toHaveCount(2)
        ->and(array_column($sentinel, 'id'))
        ->toBe(['sentinel-unit', 'sentinel-database'])
        ->and(array_column($sentinel, 'database'))
        ->toBe(['sqlite', 'mysql'])
        ->and($behaviour)
        ->toHaveCount(6)
        ->and($unit)
        ->toHaveCount(5)
        ->and($portability)
        ->toHaveCount(4)
        ->and(array_column($portability, 'id'))
        ->toBe([
            'l13-portability-sqlite',
            'l13-portability-mysql-8',
            'l13-portability-mariadb-10-5',
            'l13-portability-postgresql-16',
        ])
        ->and(array_column($portability, 'database'))
        ->toBe(['sqlite', 'mysql', 'mariadb', 'postgresql'])
        ->and(array_column($portability, 'database_driver'))
        ->toBe(['sqlite', 'mysql', 'mariadb', 'pgsql'])
        ->and(array_column($portability, 'database_version'))
        ->toBe(['runtime', '8.0', '10.5', '16'])
        ->and(array_column($portability, 'database_image'))
        ->toBe(['none', 'mysql:8.0', 'mariadb:10.5', 'postgres:16'])
        ->and(array_column($portability, 'test_group'))
        ->each->toBe('database-portability')
        ->and(array_column($portability, 'command'))
        ->each->toBe('test:database:portability:ci');

    foreach (['13.*' => '11.*'] as $laravel => $testbench) {
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

it('exports focused database portability cells for complete and targeted runs', function (): void {
    $root = dirname(__DIR__, 2);
    $script = escapeshellarg($root . '/scripts/test-all-matrix.php');

    exec(sprintf('php %s portability', $script), $allOutput, $allExitCode);
    exec(
        sprintf('php %s target --cell=l13-portability-postgresql-16', $script),
        $targetOutput,
        $targetExitCode,
    );

    $allCells = json_decode(implode(PHP_EOL, $allOutput), true, flags: JSON_THROW_ON_ERROR)['include'];
    $targetCells = json_decode(implode(PHP_EOL, $targetOutput), true, flags: JSON_THROW_ON_ERROR)['include'];

    expect($allExitCode)->toBe(0)
        ->and($allCells)
        ->toHaveCount(4)
        ->and($targetExitCode)->toBe(0)
        ->and($targetCells)->toHaveCount(1)
        ->and($targetCells[0])->toMatchArray([
            'id' => 'l13-portability-postgresql-16',
            'database' => 'postgresql',
            'database_driver' => 'pgsql',
            'database_version' => '16',
        ]);
});

it('passes each portability family and driver through the cell runtime', function (
    string $cell,
    string $family,
    string $driver,
    string $version,
): void {
    $root = dirname(__DIR__, 2);
    $temporaryDirectory = sys_get_temp_dir() . '/capell-cell-runtime-' . bin2hex(random_bytes(6));
    $capturePath = $temporaryDirectory . '/environment.json';
    mkdir($temporaryDirectory, 0700, true);
    $composerPath = $temporaryDirectory . '/composer';
    file_put_contents($composerPath, <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

file_put_contents((string) getenv('CAPTURE_PATH'), json_encode([
    'argv' => $argv,
    'database_connection' => getenv('DB_CONNECTION'),
    'database_family' => getenv('CAPELL_TEST_DATABASE_FAMILY'),
    'database_version' => getenv('CAPELL_TEST_DATABASE_VERSION'),
], JSON_THROW_ON_ERROR));
PHP);
    chmod($composerPath, 0700);

    $environment = [
        'CAPTURE_PATH' => $capturePath,
        'DB_DATABASE' => 'capell_portability_test',
        'DB_HOST' => '127.0.0.1',
        'DB_PASSWORD' => 'capell-test',
        'DB_PORT' => $driver === 'pgsql' ? '5432' : '3306',
        'DB_USERNAME' => $driver === 'pgsql' ? 'postgres' : 'root',
        'PATH' => $temporaryDirectory . PATH_SEPARATOR . (string) getenv('PATH'),
    ];
    $command = implode(' ', array_map(
        static fn (string $key, string $value): string => $key . '=' . escapeshellarg($value),
        array_keys($environment),
        $environment,
    )) . sprintf(
        ' php %s --cell=%s --output-dir=%s',
        escapeshellarg($root . '/scripts/run-test-all-cell.php'),
        escapeshellarg($cell),
        escapeshellarg($temporaryDirectory . '/output'),
    );

    try {
        exec($command, $output, $exitCode);
        $captured = json_decode((string) file_get_contents($capturePath), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($captured)->toMatchArray([
                'database_connection' => $driver,
                'database_family' => $family,
                'database_version' => $version,
            ])
            ->and(array_slice($captured['argv'], 1))->toBe(['run', 'test:database:portability:ci']);
    } finally {
        @unlink($capturePath);
        @unlink($temporaryDirectory . '/output/pest-output-portability-' . str_replace('l13-portability-', '', $cell) . '.txt');
        @rmdir($temporaryDirectory . '/output');
        @unlink($composerPath);
        @rmdir($temporaryDirectory);
    }
})->with([
    'MariaDB 10.5' => ['l13-portability-mariadb-10-5', 'mariadb', 'mariadb', '10.5'],
    'PostgreSQL 16' => ['l13-portability-postgresql-16', 'postgresql', 'pgsql', '16'],
]);

it('describes a distinct disposable service for each server database family', function (
    string $cellId,
    string $image,
    string $passwordVariable,
    string $username,
    string $port,
): void {
    $cell = TestAllMatrix::find($cellId);
    $service = new TestAllDatabaseService(
        cell: $cell,
        containerName: 'capell-test-all-1234-abcdef-' . $cell['database'],
        databaseName: 'capell_test_abcdef',
    );
    $startCommand = $service->startCommand();
    $environment = $service->connectionEnvironment('49152');

    expect($service->isServer())->toBeTrue()
        ->and($startCommand)->toContain(
            '--rm',
            '--name',
            'capell-test-all-1234-abcdef-' . $cell['database'],
            '--publish',
            '127.0.0.1::' . $port,
            $image,
            $passwordVariable . '=capell-test',
        )
        ->and($startCommand)->toContain('--health-cmd=' . $cell['database_health_command'])
        ->and($environment)->toBe([
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '49152',
            'DB_DATABASE' => 'capell_test_abcdef',
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => 'capell-test',
        ])
        ->and($service->portCommand())->toBe([
            'docker',
            'port',
            'capell-test-all-1234-abcdef-' . $cell['database'],
            $port . '/tcp',
        ])
        ->and($service->stopCommand())->toBe([
            'docker',
            'rm',
            '--force',
            'capell-test-all-1234-abcdef-' . $cell['database'],
        ]);
})->with([
    'MySQL 8' => ['l13-portability-mysql-8', 'mysql:8.0', 'MYSQL_ROOT_PASSWORD', 'root', '3306'],
    'MariaDB 10.5' => ['l13-portability-mariadb-10-5', 'mariadb:10.5', 'MARIADB_ROOT_PASSWORD', 'root', '3306'],
    'PostgreSQL 16' => ['l13-portability-postgresql-16', 'postgres:16', 'POSTGRES_PASSWORD', 'postgres', '5432'],
]);

it('keeps SQLite in-process instead of disguising it as a server service', function (): void {
    $service = new TestAllDatabaseService(
        cell: TestAllMatrix::find('l13-portability-sqlite'),
        containerName: 'unused',
        databaseName: 'unused',
    );

    expect($service->isServer())->toBeFalse()
        ->and(fn (): array => $service->startCommand())
        ->toThrow(LogicException::class, 'SQLite portability cells do not start a database service.');
});

it('defines a focused database portability group with generated test databases', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $pest = (string) file_get_contents($root . '/tests/Pest.php');
    $command = $composer['scripts']['test:database:portability:ci'] ?? null;

    expect($command)->toBeString()
        ->toContain('vendor/bin/pest')
        ->toContain('--group=database-portability')
        ->toContain('--fail-on-empty-test-suite')
        ->toContain('--log-junit=${PEST_JUNIT_LOG:?PEST_JUNIT_LOG must be set}')
        ->not->toContain('--parallel')
        ->and($pest)
        ->toContain("pest()->group('database-portability')->in(")
        ->toContain('DatabaseCompatibilityTest.php')
        ->toContain('PermissionTeamsMigrationTest.php')
        ->toContain('GlobalPermissionTeamUniquenessMigrationTest.php')
        ->toContain('DatabaseBackupDriversTest.php')
        ->toContain('DatabasePortabilityEnvironmentTest.php');

    foreach ([
        'packages/core/tests/Feature/Commands/DoctorCommandTest.php',
        'packages/core/tests/Feature/Console/UpgradeCommandTest.php',
        'packages/core/tests/Feature/Database/DatabasePortabilityEnvironmentTest.php',
        'packages/core/tests/Feature/Install/RunInstallActionTest.php',
        'packages/core/tests/Feature/Permissions/GlobalPermissionTeamUniquenessMigrationTest.php',
        'packages/core/tests/Feature/Permissions/PermissionTeamsMigrationTest.php',
        'packages/core/tests/Unit/Backup/DatabaseBackupDriversTest.php',
        'packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php',
    ] as $testPath) {
        expect((string) file_get_contents($root . '/' . $testPath))
            ->not->toContain('markTestSkipped')
            ->not->toContain('->skip(');
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
        ->toContain('scripts/test-all-matrix.php portability')
        ->toContain('scripts/prepare-test-all-dependencies.php')
        ->toContain('scripts/run-test-all-cell.php')
        ->toContain('scripts/run-test-all-portability-cell.php')
        ->and($localRunner)
        ->toContain("getopt('', ['cell:', 'output-dir:'])")
        ->toContain('$selectedCells')
        ->toContain('scripts/prepare-test-all-dependencies.php')
        ->toContain('scripts/run-test-all-cell.php')
        ->toContain('scripts/run-test-all-portability-cell.php')
        ->toContain("'git', 'worktree', 'add', '--detach'")
        ->toContain("'git', 'worktree', 'remove', '--force'")
        ->toMatch("/'docker',\s*'run'/")
        ->toContain('/summary.json')
        ->toContain('reapStaleTestAllContainers($repositoryRoot)')
        ->toContain("'name=^capell-test-all-'")
        ->toContain('[0-9a-f]{6}(?:-[a-z0-9-]+)?')
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
        'php %s target --cell=l13-feature-admin',
        escapeshellarg($root . '/scripts/test-all-matrix.php'),
    );
    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0);

    $matrix = json_decode(implode(PHP_EOL, $output), true, flags: JSON_THROW_ON_ERROR);

    expect($matrix['include'])->toHaveCount(1)
        ->and($matrix['include'][0]['id'])->toBe('l13-feature-admin')
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
