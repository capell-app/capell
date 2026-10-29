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

$junitPath = $environment['PEST_JUNIT_LOG'];

if (file_exists($junitPath)) {
    fwrite(STDERR, 'Test All evidence already exists; use a fresh output directory: ' . $junitPath . PHP_EOL);

    exit(2);
}

fwrite(STDOUT, sprintf('Running Test All cell [%s].', $cellId) . PHP_EOL);

$command = ['composer', 'run', $cell['command']];

if (isset($cell['shard'])) {
    $command = [...$command, '--', '--shard=' . $cell['shard']];
}

$exitCode = ProcessRunner::run(
    $command,
    dirname(__DIR__),
    $environment,
    $outputDirectory . DIRECTORY_SEPARATOR . $cell['log'],
);

if ($exitCode !== 0) {
    exit($exitCode);
}

$previousXmlErrors = libxml_use_internal_errors(true);
try {
    $report = is_file($junitPath) ? simplexml_load_file($junitPath, options: LIBXML_NONET) : false;
    $valid = $report instanceof SimpleXMLElement
        && in_array($report->getName(), ['testsuites', 'testsuite'], true)
        && count($report->xpath('//testcase[not(skipped)]') ?: []) > 0
        && ($report->xpath('//failure | //error') ?: []) === [];
} finally {
    libxml_clear_errors();
    libxml_use_internal_errors($previousXmlErrors);
}

if (! $valid) {
    fwrite(STDERR, 'Test All evidence is missing, invalid, empty or contains failures despite a successful command: ' . $junitPath . PHP_EOL);

    exit(2);
}

exit(0);
