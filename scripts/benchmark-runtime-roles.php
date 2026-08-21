<?php

declare(strict_types=1);

use Capell\Benchmark\BootBenchmark;
use Capell\Benchmark\BootBenchmarkOptions;
use Capell\Benchmark\RuntimeRoleBenchmarkComparison;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/benchmark-boot-support.php';

$arguments = array_values(array_filter(
    array_slice($GLOBALS['argv'] ?? [], 1),
    is_string(...),
));

throw_if(
    array_any($arguments, static fn (string $argument): bool => str_starts_with($argument, '--profile=')),
    InvalidArgumentException::class,
    'The paired runtime benchmark fixes the profiles to combined and public; omit --profile.',
);

$parsed = BootBenchmarkOptions::fromArguments([...$arguments, '--profile=combined']);
$benchmark = new BootBenchmark(dirname(__DIR__));
$combined = $benchmark->run($parsed);
$public = $benchmark->run(new BootBenchmarkOptions(
    profile: 'public',
    cache: $parsed->cache,
    iterations: $parsed->iterations,
    warmups: $parsed->warmups,
    format: $parsed->format,
    profiling: $parsed->profiling,
));
$comparison = RuntimeRoleBenchmarkComparison::summarize($combined, $public);
$result = [
    'schema_version' => 1,
    'benchmark' => [
        'cache' => $parsed->cache,
        'iterations' => $parsed->iterations,
        'warmups' => $parsed->warmups,
    ],
    'comparison' => $comparison,
    'roles' => [
        'combined' => $combined,
        'public' => $public,
    ],
];

if ($parsed->format === 'json') {
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo sprintf(
        "Combined: %.2f ms p50, %.2f ms p75\nPublic: %.2f ms p50, %.2f ms p75\nDelta: %.2f ms p50, %.2f ms p75\n",
        $comparison['combined_p50_ms'],
        $comparison['combined_p75_ms'],
        $comparison['public_p50_ms'],
        $comparison['public_p75_ms'],
        $comparison['p50_delta_ms'],
        $comparison['p75_delta_ms'],
    );
}

throw_if(
    $comparison['public_p75_regressed'],
    RuntimeException::class,
    'The public runtime role regressed at p75 against the paired combined run.',
);
