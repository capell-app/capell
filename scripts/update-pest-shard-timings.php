<?php

declare(strict_types=1);

/**
 * Rebuilds tests/.pest/shards.json from real per-file JUnit timings.
 *
 * Pest's own `--update-shards` (composer test:shards) writes timings keyed by
 * namespaced class name, and leaves files it cannot resolve unweighted. The shard
 * runner then partitions those files at the default weight of 1.0, so a single very
 * slow file can silently dominate one shard while the estimated weights look level.
 *
 * This runs the sharded suite once with `--log-junit` enabled for every shard, then
 * folds each test file's wall time back into the manifest keyed by repo-relative
 * path — the form scripts/run-pest-shards.php resolves.
 *
 * Usage: php scripts/update-pest-shard-timings.php
 */
$root = dirname(__DIR__);
$junitDirectory = $root . '/tests/.pest/junit';
$manifestPath = $root . '/tests/.pest/shards.json';

/**
 * Per-shard process boot cost. Subtracted from each file's measured time so weights
 * reflect marginal cost rather than a constant that the partitioner cannot move.
 */
const PROCESS_BOOT_BASELINE_SECONDS = 0.51;

foreach (glob($junitDirectory . '/*.xml') ?: [] as $staleReport) {
    unlink($staleReport);
}

$command = sprintf(
    'PEST_SHARDS_JUNIT_DIR=%s %s -d memory_limit=1536M %s',
    escapeshellarg($junitDirectory),
    escapeshellarg(PHP_BINARY),
    escapeshellarg($root . '/scripts/run-pest-shards.php'),
);

passthru($command, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, 'Sharded run failed; timing manifest left untouched.' . PHP_EOL);

    // A top-level `return` does not set the process exit status, so CI would have treated a
    // failing sharded run as a passing one. Exit explicitly.
    exit($exitCode);
}

$timings = [];

$collect = static function (SimpleXMLElement $node) use (&$collect, &$timings, $root): void {
    $file = (string) ($node['file'] ?? '');

    if ($file !== '' && isset($node['tests'])) {
        $relativePath = str_replace($root . '/', '', $file);
        $timings[$relativePath] = round(($timings[$relativePath] ?? 0.0) + (float) $node['time'], 2);

        return;
    }

    foreach ($node->testsuite as $childNode) {
        $collect($childNode);
    }
};

foreach (glob($junitDirectory . '/*.xml') ?: [] as $report) {
    $document = simplexml_load_file($report);

    if ($document === false) {
        fwrite(STDERR, "Unable to parse JUnit report [{$report}]." . PHP_EOL);

        exit(1);
    }

    $collect($document);
}

if ($timings === []) {
    fwrite(STDERR, 'No JUnit timings were collected.' . PHP_EOL);

    exit(1);
}

foreach ($timings as $relativePath => $measuredSeconds) {
    $timings[$relativePath] = max(0.01, round($measuredSeconds - PROCESS_BOOT_BASELINE_SECONDS, 2));
}

$manifest = is_file($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR)
    : ['timings' => []];

$mergedTimings = array_merge($manifest['timings'] ?? [], $timings);
ksort($mergedTimings);
$manifest['timings'] = $mergedTimings;
$manifest['updated_at'] = gmdate('Y-m-d\TH:i:sP');

file_put_contents(
    $manifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);

foreach (glob($junitDirectory . '/*.xml') ?: [] as $report) {
    unlink($report);
}

printf("[pest-shards] Updated %d file timings in tests/.pest/shards.json.\n", count($timings));

exit(0);
