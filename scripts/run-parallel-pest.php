<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$processes = getenv('PEST_PROCESSES') ?: '4';
$shard = getenv('PEST_SHARD') ?: '1/1';

throw_if(preg_match('/^[1-9]\d*$/', $processes) !== 1, InvalidArgumentException::class, 'PEST_PROCESSES must be a positive integer.');

throw_if(preg_match('/^[1-9]\d*\/[1-9]\d*$/', $shard) !== 1, InvalidArgumentException::class, 'PEST_SHARD must use the N/TOTAL format.');

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

throw_if($exitCode !== 0, RuntimeException::class, sprintf('Pest failed with exit code %d.', $exitCode), $exitCode);
