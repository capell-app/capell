<?php

declare(strict_types=1);

use Capell\Tests\Support\CommandFixture;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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

        $configuration = simplexml_load_file(dirname(__DIR__, 2) . '/phpunit.xml');

        throw_unless($configuration instanceof SimpleXMLElement, RuntimeException::class, 'phpunit.xml could not be parsed as XML.');

        $standardSuites = [];
        foreach ($configuration->testsuites->testsuite as $suite) {
            $standardSuites[] = (string) $suite['name'];
        }

        $matrixSuites = array_values(array_unique(array_column([...$frameworkBehaviour, ...$frameworkUnit], 'test_suite')));
        sort($standardSuites);
        sort($matrixSuites);
        expect($matrixSuites)->toBe($standardSuites);
        expect(array_column($frameworkBehaviour, 'testbench'))->each->toBe($testbench);
        expect(array_column($frameworkUnit, 'testbench'))->each->toBe($testbench);
        foreach ($standardSuites as $suite) {
            $suiteCells = array_values(array_filter(
                [...$frameworkBehaviour, ...$frameworkUnit],
                static fn (array $cell): bool => $cell['test_suite'] === $suite,
            ));
            // Unit/Feature split by owning package; other suites run unfiltered
            // so a new architecture or integration directory cannot disappear.
            if (in_array($suite, ['Unit', 'Feature'], true)) {
                $directories = glob(dirname(__DIR__, 2) . '/packages/*/tests/' . $suite, GLOB_ONLYDIR) ?: [];
                $expectedGroups = array_map(static fn (string $path): string => basename(dirname($path, 2)), $directories);
                $actualGroups = array_column($suiteCells, 'test_group');
                sort($expectedGroups);
                sort($actualGroups);
                expect($actualGroups)->toBe($expectedGroups)
                    ->and(array_column($suiteCells, 'command'))->each->toBe('test:database:package:ci');
            } else {
                expect($suiteCells)->toHaveCount(1)
                    ->and($suiteCells[0]['command'])->toBe('test:database:ci');
            }

            expect(array_column($suiteCells, 'database'))->each->toBe(
                in_array($suite, ['Unit', 'Arch'], true) ? 'sqlite' : 'mysql',
            );
        }
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
        'PATH' => $temporaryDirectory . PATH_SEPARATOR . getenv('PATH'),
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

it('discovers the database portability group through the repository Pest configuration', function (): void {
    $process = new Process([PHP_BINARY, 'vendor/bin/pest', '--configuration=phpunit.xml', '--group=database-portability', '--list-tests'], dirname(__DIR__, 2));
    expect($process->run())->toBe(0, $process->getErrorOutput());
    foreach (['DatabaseCompatibilityTest', 'PermissionTeamsMigrationTest', 'GlobalPermissionTeamUniquenessMigrationTest', 'DatabaseBackupDriversTest', 'DatabasePortabilityEnvironmentTest'] as $test) {
        expect($process->getOutput())->toContain($test);
    }
});

it('exports hosted matrix outcomes from the shared repository definition', function (): void {
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/test-full.yml');
    $steps = array_column($workflow['jobs']['matrix']['steps'], null, 'name');
    $fixture = new CommandFixture;
    $fixture->files->copy('scripts/test-all-matrix.php');
    $fixture->files->copy('scripts/test-all/TestAllMatrix.php');
    try {
        $process = $fixture->run(['bash', '-e', '-c', $steps['Export shared matrix']['run']], ['TARGET_CELL' => '', 'GITHUB_OUTPUT' => $fixture->files->root . '/output']);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        $lines = file($fixture->files->root . '/output', FILE_IGNORE_NEW_LINES);
        expect($lines)->toBeArray()->toHaveCount(3);
        throw_unless(is_array($lines), RuntimeException::class, 'The hosted matrix must produce an output file.');
        foreach ($lines as $line) {
            [$kind, $json] = explode('=', $line, 2);
            $expected = match ($kind) {
                'behaviour' => TestAllMatrix::behaviour(),
                'unit' => TestAllMatrix::unit(),
                'portability' => TestAllMatrix::portability(),
                default => throw new RuntimeException('Unexpected hosted matrix output: ' . $kind),
            };
            expect(json_decode($json, true, flags: JSON_THROW_ON_ERROR)['include'])->toBe($expected);
        }
    } finally {
        $fixture->close();
    }
});

it('runs an isolated selected matrix cell and preserves its evidence and failure status', function (string $cell, bool $fail): void {
    $fixture = new CommandFixture;
    foreach (['scripts/run-test-all-matrix.php', 'scripts/test-all/ProcessRunner.php', 'scripts/test-all/TestAllMatrix.php'] as $path) {
        $fixture->files->copy($path);
    }

    $fixture->files->write('cell.php', <<<'PHP'
        <?php
        file_put_contents(getenv('MATRIX_FIXTURE_CAPTURE'), json_encode([
            'runner' => basename($argv[0]), 'arguments' => array_slice($argv, 1),
            'workers' => getenv('PEST_MAX_PROCESSES'), 'cwd' => getcwd(),
        ], JSON_THROW_ON_ERROR));
        if (getenv('MATRIX_FIXTURE_FAIL') === 'true') { throw new RuntimeException('Cell fixture failed'); }
        PHP);
    $fixture->fake('git', <<<'PHP'
        if (in_array('rev-parse', $argv, true)) { echo str_repeat('a', 40); }
        if (($argv[1] ?? '') === 'worktree' && ($argv[2] ?? '') === 'add') {
            $workspace = $argv[4];
            mkdir($workspace . '/scripts', 0755, true);
            file_put_contents($workspace . '/scripts/prepare-test-all-dependencies.php', '<?php');
            foreach (['run-test-all-cell.php', 'run-test-all-portability-cell.php'] as $runner) {
                copy(getenv('MATRIX_FIXTURE_CELL'), $workspace . '/scripts/' . $runner);
            }
        }
        PHP);
    try {
        $process = $fixture->run([PHP_BINARY, 'scripts/run-test-all-matrix.php', '--cell=' . $cell, '--output-dir=evidence'], [
            'MATRIX_FIXTURE_CELL' => $fixture->files->root . '/cell.php',
            'MATRIX_FIXTURE_CAPTURE' => $fixture->files->root . '/captured.json',
            'MATRIX_FIXTURE_FAIL' => $fail ? 'true' : 'false',
        ]);
        expect($process->getExitCode())->toBe($fail ? 1 : 0, $process->getErrorOutput());
        $summary = json_decode((string) file_get_contents($fixture->files->root . '/evidence/summary.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($summary['results'])->toBe([['id' => $cell, 'exit_code' => $fail ? 255 : 0, 'status' => $fail ? 'failed' : 'passed']]);
        $captured = json_decode((string) file_get_contents($fixture->files->root . '/captured.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($captured['runner'])->toBe(str_contains($cell, 'portability') ? 'run-test-all-portability-cell.php' : 'run-test-all-cell.php')
            ->and($captured['arguments'])->toBe(['--cell=' . $cell, '--output-dir=' . $fixture->files->root . '/evidence/' . $cell])
            ->and($captured['cwd'])->not->toBe(dirname(__DIR__, 2))
            ->and(is_dir($captured['cwd']))->toBeFalse();
        expect(array_filter($fixture->calls('docker'), static fn (array $call): bool => ($call['arguments'][0] ?? '') === 'run'))->toBe([]);
    } finally {
        $fixture->close();
    }
})->with([
    ['l13-unit-core', false], ['l13-portability-sqlite', false], ['l13-unit-core', true],
]);

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
