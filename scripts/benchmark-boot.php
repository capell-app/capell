<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

$iterations = filter_var($argv[1] ?? 10, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 3, 'max_range' => 100],
]);

if (! is_int($iterations)) {
    throw new InvalidArgumentException('Usage: php scripts/benchmark-boot.php [iterations: 3-100]');
}

$root = dirname(__DIR__);
$cachePath = $root . '/vendor/orchestra/testbench-core/laravel/bootstrap/cache/capell-package-manifests.php';

if (! is_file($cachePath)) {
    $warm = new Process([PHP_BINARY, 'vendor/bin/testbench', 'capell:package-cache', '--no-ansi'], $root);
    $warm->mustRun();
}

$samples = [];

for ($iteration = 1; $iteration <= $iterations; $iteration++) {
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'about', '--only=environment', '--no-ansi'],
        $root,
        ['APP_RUNNING_IN_CONSOLE' => 'false'],
    );

    $startedAt = hrtime(true);
    $process->mustRun();
    $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
}

sort($samples, SORT_NUMERIC);
$middle = intdiv($iterations, 2);
$median = $iterations % 2 === 0
    ? ($samples[$middle - 1] + $samples[$middle]) / 2
    : $samples[$middle];

printf(
    "Capell non-console boot: %.2f ms median (%d isolated boots)\nSamples: %s\n",
    $median,
    $iterations,
    implode(', ', array_map(static fn (float $sample): string => sprintf('%.2f', $sample), $samples)),
);
