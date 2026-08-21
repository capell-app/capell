<?php

declare(strict_types=1);

require_once __DIR__ . '/test-all/ProcessRunner.php';
require_once __DIR__ . '/test-all/TestAllDatabaseService.php';
require_once __DIR__ . '/test-all/TestAllMatrix.php';

$parsedOptions = getopt('', ['cell:', 'output-dir:']);
$options = is_array($parsedOptions) ? $parsedOptions : [];
$cellId = $options['cell'] ?? null;
$outputDirectory = $options['output-dir'] ?? null;
$repositoryRoot = dirname(__DIR__);

if (! is_string($cellId) || ! is_string($outputDirectory)) {
    fwrite(STDERR, 'Usage: php scripts/run-test-all-portability-cell.php --cell=<cell-id> --output-dir=<path>' . PHP_EOL);

    exit(2);
}

$cell = TestAllMatrix::find($cellId);

if (($cell['kind'] ?? null) !== 'portability') {
    fwrite(STDERR, sprintf('Test All cell [%s] is not a database portability cell.', $cellId) . PHP_EOL);

    exit(2);
}

$token = bin2hex(random_bytes(4));
$containerName = sprintf(
    'capell-test-all-%d-%s-%s',
    getmypid(),
    substr($token, 0, 6),
    str_replace('_', '-', $cell['database']),
);
$databaseName = 'capell_test_' . $token;
$service = new TestAllDatabaseService($cell, $containerName, $databaseName);
$containerStarted = false;
$exitCode = 1;
$failure = null;

try {
    $environment = [];

    if ($service->isServer()) {
        $start = ProcessRunner::capture($service->startCommand(), $repositoryRoot);

        if ($start['exit_code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Unable to start the isolated %s %s Test All service: %s',
                $cell['database'],
                $cell['database_version'],
                $start['output'],
            ));
        }

        $containerStarted = true;
        $deadline = microtime(true) + 120;
        $health = ['exit_code' => 1, 'output' => 'starting'];

        do {
            $health = ProcessRunner::capture([
                'docker',
                'inspect',
                '--format={{.State.Health.Status}}',
                $containerName,
            ], $repositoryRoot);

            if ($health['exit_code'] === 0 && $health['output'] === 'healthy') {
                break;
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);

        if ($health['exit_code'] !== 0 || $health['output'] !== 'healthy') {
            throw new RuntimeException(sprintf(
                'The isolated %s %s Test All service did not become healthy.',
                $cell['database'],
                $cell['database_version'],
            ));
        }

        $port = ProcessRunner::capture($service->portCommand(), $repositoryRoot);

        if ($port['exit_code'] !== 0 || preg_match('/:(\d+)$/', $port['output'], $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Unable to resolve the isolated %s %s Test All port.',
                $cell['database'],
                $cell['database_version'],
            ));
        }

        $environment = $service->connectionEnvironment($matches[1]);
    }

    fwrite(STDOUT, sprintf(
        'Running database portability cell [%s] on an isolated generated database.',
        $cellId,
    ) . PHP_EOL);

    $exitCode = ProcessRunner::run(
        [
            PHP_BINARY,
            'scripts/run-test-all-cell.php',
            '--cell=' . $cellId,
            '--output-dir=' . $outputDirectory,
        ],
        $repositoryRoot,
        $environment,
    );
} catch (Throwable $throwable) {
    $failure = $throwable;
} finally {
    if ($containerStarted) {
        ProcessRunner::capture($service->stopCommand(), $repositoryRoot);
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);

    exit(1);
}

exit($exitCode);
