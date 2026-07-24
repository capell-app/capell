<?php

declare(strict_types=1);

use Capell\Benchmark\BootBenchmark;
use Capell\Benchmark\BootBenchmarkOptions;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/benchmark-boot-support.php';

try {
    $arguments = array_values(array_filter(
        $_SERVER['argv'] ?? [],
        static fn (mixed $argument): bool => is_string($argument),
    ));
    $options = BootBenchmarkOptions::fromArguments(array_slice($arguments, 1));
    $result = (new BootBenchmark(dirname(__DIR__)))->run($options);

    echo $options->format === 'json'
        ? json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : BootBenchmark::formatText($result);
} catch (InvalidArgumentException $exception) {
    throw new InvalidArgumentException(
        $exception->getMessage() . PHP_EOL . BootBenchmarkOptions::usage(),
        previous: $exception,
    );
}
