<?php

declare(strict_types=1);

require_once __DIR__ . '/test-all/ProcessRunner.php';
require_once __DIR__ . '/test-all/TestAllMatrix.php';

$parsedOptions = getopt('', ['output-dir:']);
$options = is_array($parsedOptions) ? $parsedOptions : [];
$repositoryRoot = dirname(__DIR__);
$headResult = ProcessRunner::capture(['git', 'rev-parse', 'HEAD'], $repositoryRoot);

if ($headResult['exit_code'] !== 0 || preg_match('/^[0-9a-f]{40}$/', $headResult['output']) !== 1) {
    throw new RuntimeException('Unable to resolve the exact Core HEAD for the local Test All matrix.');
}

$head = $headResult['output'];
$requestedOutputDirectory = $options['output-dir'] ?? null;
$outputDirectory = is_string($requestedOutputDirectory)
    ? $requestedOutputDirectory
    : $repositoryRoot . '/.test-all-results/' . gmdate('YmdHis') . '-' . substr((string) $head, 0, 12);
$outputDirectory = str_starts_with($outputDirectory, DIRECTORY_SEPARATOR)
    ? $outputDirectory
    : $repositoryRoot . DIRECTORY_SEPARATOR . $outputDirectory;

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException(sprintf('Unable to create Test All output directory [%s].', $outputDirectory));
}

$temporaryRoot = sys_get_temp_dir() . '/capell-test-all-' . getmypid() . '-' . bin2hex(random_bytes(4));

if (! mkdir($temporaryRoot, 0700, true) && ! is_dir($temporaryRoot)) {
    throw new RuntimeException(sprintf('Unable to create isolated Test All workspace [%s].', $temporaryRoot));
}

$containerName = 'capell-test-all-' . getmypid() . '-' . bin2hex(random_bytes(3));
$containerStarted = false;
/** @var list<string> $createdWorktrees */
$createdWorktrees = [];
/** @var list<array{id: string, exit_code: int, status: string}> $results */
$results = [];
$startedAt = gmdate(DATE_ATOM);
$fatalError = null;

try {
    /** @var array<string, array{path: string, prepare_exit_code: int}> $workspaces */
    $workspaces = [];

    foreach ([
        'l13' => ['laravel' => '13.*', 'testbench' => '11.*'],
    ] as $slug => $framework) {
        $workspace = $temporaryRoot . DIRECTORY_SEPARATOR . $slug;
        $worktreeExitCode = ProcessRunner::run(
            ['git', 'worktree', 'add', '--detach', $workspace, $head],
            $repositoryRoot,
        );

        if ($worktreeExitCode !== 0) {
            throw new RuntimeException(sprintf('Unable to create isolated Core worktree [%s] at [%s].', $workspace, $head));
        }

        $createdWorktrees[] = $workspace;
        $prepareLog = $outputDirectory . DIRECTORY_SEPARATOR . sprintf('dependencies-%s.log', $slug);
        $prepareExitCode = ProcessRunner::run(
            [
                PHP_BINARY,
                'scripts/prepare-test-all-dependencies.php',
                '--laravel=' . $framework['laravel'],
                '--testbench=' . $framework['testbench'],
            ],
            $workspace,
            logPath: $prepareLog,
        );

        $workspaces[$slug] = [
            'path' => $workspace,
            'prepare_exit_code' => $prepareExitCode,
        ];
    }

    $dockerRun = ProcessRunner::capture([
        'docker',
        'run',
        '--detach',
        '--rm',
        '--name',
        $containerName,
        '--health-cmd=mysqladmin ping -h 127.0.0.1 -proot || exit 1',
        '--health-interval=2s',
        '--health-timeout=2s',
        '--health-retries=60',
        '--env',
        'MYSQL_ROOT_PASSWORD=root',
        '--env',
        'MYSQL_DATABASE=test_cms_multi',
        '--publish',
        '127.0.0.1::3306',
        'mysql:8.0',
    ], $repositoryRoot);

    if ($dockerRun['exit_code'] !== 0) {
        throw new RuntimeException('Unable to start isolated MySQL 8 Test All service: ' . $dockerRun['output']);
    }

    $containerStarted = true;
    $deadline = time() + 120;

    do {
        $health = ProcessRunner::capture(
            ['docker', 'inspect', '--format={{.State.Health.Status}}', $containerName],
            $repositoryRoot,
        );

        if ($health['exit_code'] === 0 && $health['output'] === 'healthy') {
            break;
        }

        sleep(2);
    } while (time() < $deadline);

    if ($health['exit_code'] !== 0 || $health['output'] !== 'healthy') {
        throw new RuntimeException('The isolated MySQL 8 Test All service did not become healthy.');
    }

    $portResult = ProcessRunner::capture(['docker', 'port', $containerName, '3306/tcp'], $repositoryRoot);

    if ($portResult['exit_code'] !== 0 || preg_match('/:(\d+)$/', $portResult['output'], $matches) !== 1) {
        throw new RuntimeException('Unable to resolve the isolated MySQL 8 Test All port.');
    }

    $mysqlEnvironment = [
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => $matches[1],
        'DB_DATABASE' => 'test_cms_multi',
        'DB_USERNAME' => 'root',
        'DB_PASSWORD' => 'root',
    ];

    $tuneExitCode = ProcessRunner::run([
        'docker',
        'exec',
        $containerName,
        'mysql',
        '--user=root',
        '--password=root',
        '--execute=SET GLOBAL innodb_redo_log_capacity = 2147483648; SET GLOBAL innodb_flush_log_at_trx_commit = 2; SET GLOBAL sync_binlog = 0;',
    ], $repositoryRoot);

    if ($tuneExitCode !== 0) {
        throw new RuntimeException('Unable to tune the isolated MySQL 8 Test All service.');
    }

    $createSentinelDatabaseExitCode = ProcessRunner::run([
        'docker',
        'exec',
        $containerName,
        'mysql',
        '--user=root',
        '--password=root',
        '--execute=CREATE DATABASE IF NOT EXISTS test_cms_sentinel;',
    ], $repositoryRoot);

    if ($createSentinelDatabaseExitCode !== 0) {
        throw new RuntimeException('Unable to create the isolated sentinel database.');
    }

    foreach (TestAllMatrix::all() as $cell) {
        $workspace = $workspaces['l13'];
        $cellOutputDirectory = $outputDirectory . DIRECTORY_SEPARATOR . $cell['id'];

        if ($workspace['prepare_exit_code'] !== 0) {
            $results[] = [
                'id' => $cell['id'],
                'exit_code' => $workspace['prepare_exit_code'],
                'status' => 'dependency-preparation-failed',
            ];

            continue;
        }

        $cellEnvironment = [
            ...$mysqlEnvironment,
            'DB_DATABASE' => str_starts_with($cell['id'], 'sentinel-')
                ? 'test_cms_sentinel'
                : 'test_cms_multi',
        ];
        $exitCode = ProcessRunner::run(
            [
                PHP_BINARY,
                'scripts/run-test-all-cell.php',
                '--cell=' . $cell['id'],
                '--output-dir=' . $cellOutputDirectory,
            ],
            $workspace['path'],
            $cellEnvironment,
        );
        $results[] = [
            'id' => $cell['id'],
            'exit_code' => $exitCode,
            'status' => $exitCode === 0 ? 'passed' : 'failed',
        ];
    }
} catch (Throwable $throwable) {
    $fatalError = $throwable;
    $results[] = [
        'id' => 'orchestration',
        'exit_code' => 1,
        'status' => 'failed',
    ];
    file_put_contents(
        $outputDirectory . '/orchestration-error.log',
        $throwable::class . ': ' . $throwable->getMessage() . PHP_EOL,
    );
} finally {
    if ($containerStarted) {
        ProcessRunner::capture(['docker', 'rm', '--force', $containerName], $repositoryRoot);
    }

    foreach (array_reverse($createdWorktrees) as $worktree) {
        ProcessRunner::capture(
            ['git', 'worktree', 'remove', '--force', $worktree],
            $repositoryRoot,
        );
    }

    removeTestAllTemporaryDirectory($temporaryRoot);
}

$summary = [
    'schema_version' => 1,
    'repository' => 'capell-app/capell',
    'sha' => $head,
    'started_at' => $startedAt,
    'completed_at' => gmdate(DATE_ATOM),
    'results' => $results,
];

file_put_contents(
    $outputDirectory . '/summary.json',
    json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

$failures = array_filter($results, static fn (array $result): bool => $result['exit_code'] !== 0);

fwrite(
    STDOUT,
    sprintf(
        'Test All matrix completed for %s: %d passed, %d failed. Evidence: %s%s',
        $head,
        count($results) - count($failures),
        count($failures),
        $outputDirectory,
        PHP_EOL,
    ),
);

if ($fatalError instanceof Throwable) {
    fwrite(STDERR, $fatalError->getMessage() . PHP_EOL);
}

exit($failures === [] ? 0 : 1);

function removeTestAllTemporaryDirectory(string $directory): void
{
    $expectedPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'capell-test-all-';

    if (! str_starts_with($directory, $expectedPrefix) || ! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && ! $item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($directory);
}
