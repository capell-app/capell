<?php

declare(strict_types=1);

require_once __DIR__ . '/test-all/ProcessRunner.php';
require_once __DIR__ . '/test-all/TestAllMatrix.php';

$parsedOptions = getopt('', ['cell:', 'output-dir:']);
$options = is_array($parsedOptions) ? $parsedOptions : [];
$cellId = $options['cell'] ?? null;
$currentWorkingDirectory = getcwd();

if ($currentWorkingDirectory === false) {
    throw new RuntimeException('Unable to resolve the Test All working directory.');
}

$outputDirectory = $options['output-dir'] ?? $currentWorkingDirectory;

if (! is_string($cellId) || ! is_string($outputDirectory)) {
    fwrite(STDERR, 'Usage: php scripts/run-test-all-cell.php --cell=<cell-id> [--output-dir=<path>]' . PHP_EOL);

    exit(2);
}

$cell = TestAllMatrix::find($cellId);
$outputDirectory = str_starts_with($outputDirectory, DIRECTORY_SEPARATOR)
    ? $outputDirectory
    : $currentWorkingDirectory . DIRECTORY_SEPARATOR . $outputDirectory;

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException(sprintf('Unable to create Test All output directory [%s].', $outputDirectory));
}

$environment = [
    'CAPELL_TEST_DATABASE_FAMILY' => $cell['database'],
    'CAPELL_TEST_DATABASE_VERSION' => $cell['database_version'] ?? 'runtime',
    'CACHE_STORE' => 'array',
    'NO_COLOR' => '1',
    'PAO_DISABLE' => '1',
    'PEST_JUNIT_LOG' => $outputDirectory . DIRECTORY_SEPARATOR . $cell['junit'],
    'PEST_TEST_SUITE' => $cell['test_suite'],
    'PEST_TEST_GROUP' => $cell['test_group'] ?? 'unused',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
];

$databaseDriver = $cell['database_driver'] ?? $cell['database'];

if ($databaseDriver === 'sqlite') {
    $environment['DB_CONNECTION'] = 'sqlite';
    $environment['DB_DATABASE'] = ':memory:';
} else {
    foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
        $value = getenv($required);

        if (! is_string($value) || $value === '') {
            fwrite(STDERR, sprintf('%s must be set for %s Test All cells.', $required, $cell['database']) . PHP_EOL);

            exit(2);
        }
    }

    $environment['DB_CONNECTION'] = $databaseDriver;
}

fwrite(STDOUT, sprintf('Running Test All cell [%s].', $cellId) . PHP_EOL);

exit(ProcessRunner::run(
    ['composer', 'run', $cell['command']],
    dirname(__DIR__),
    $environment,
    $outputDirectory . DIRECTORY_SEPARATOR . $cell['log'],
));
