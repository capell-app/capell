<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Run a Testbench command with the in-memory cache store required by the
 * generated workbench. Keeping the environment in Process avoids relying on
 * POSIX inline environment assignment, which Composer cannot execute on
 * Windows.
 *
 * @var list<string> $argv
 */
$arguments = array_values(array_filter(
    array_slice($argv ?? [], 1),
    static fn (mixed $argument): bool => is_string($argument),
));

if ($arguments === []) {
    throw new InvalidArgumentException('A Testbench command is required.');
}

$root = dirname(__DIR__);
$process = new Process(
    [PHP_BINARY, $root . '/vendor/bin/testbench', ...$arguments],
    $root,
    ['CACHE_STORE' => 'array'],
);
$process->setTimeout(null);

$exitCode = $process->run(static function (string $type, string $buffer): void {
    echo $buffer;
});

if ($exitCode !== 0) {
    throw new RuntimeException(sprintf('Testbench command failed with exit code %d.', $exitCode), $exitCode);
}
