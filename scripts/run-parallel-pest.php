<?php

declare(strict_types=1);

$processes = getenv('PEST_PROCESSES') ?: '4';
$shard = getenv('PEST_SHARD') ?: '1/1';

if (preg_match('/^[1-9][0-9]*$/', $processes) !== 1) {
    throw new InvalidArgumentException('PEST_PROCESSES must be a positive integer.');
}

if (preg_match('/^[1-9][0-9]*\/[1-9][0-9]*$/', $shard) !== 1) {
    throw new InvalidArgumentException('PEST_SHARD must use the N/TOTAL format.');
}

$arguments = array_map(
    static fn (string $argument): string => str_replace(
        ['{processes}', '{shard}'],
        [$processes, $shard],
        $argument,
    ),
    array_slice($argv, 1),
);

$command = array_merge(
    [PHP_BINARY, '-d', 'memory_limit=' . ini_get('memory_limit'), '-d', 'max_execution_time=0', 'vendor/bin/pest'],
    $arguments,
);

$escapedCommand = implode(' ', array_map(escapeshellarg(...), $command));
passthru($escapedCommand, $exitCode);

if ($exitCode !== 0) {
    throw new RuntimeException("Pest failed with exit code {$exitCode}.", $exitCode);
}
