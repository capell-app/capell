<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Benchmark\BootBenchmark;
use Capell\Benchmark\BootBenchmarkOptions;
use Capell\Benchmark\BootProfiles;
use Capell\Benchmark\BootStatistics;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;
use Workbench\App\Providers\ScreenshotWorkbenchServiceProvider;

require_once dirname(__DIR__, 2) . '/scripts/benchmark-boot-support.php';

it('preserves positional iterations and parses documented options', function (): void {
    $positional = BootBenchmarkOptions::fromArguments(['10']);
    $options = BootBenchmarkOptions::fromArguments([
        '--profile=public',
        '--cache=manifest',
        '--iterations=7',
        '--warmups=2',
        '--format=json',
        '--profiling',
    ]);

    expect($positional->iterations)->toBe(10)
        ->and($options->profile)->toBe('public')
        ->and($options->cache)->toBe('manifest')
        ->and($options->iterations)->toBe(7)
        ->and($options->warmups)->toBe(2)
        ->and($options->format)->toBe('json')
        ->and($options->profiling)->toBeTrue();
});

it('rejects invalid arguments and ranges', function (array $arguments): void {
    BootBenchmarkOptions::fromArguments($arguments);
})->with([
    [['--profile=unknown']],
    [['--cache=unknown']],
    [['--iterations=2']],
    [['--warmups=26']],
    [['--format=xml']],
    [['5', '--iterations=5']],
    [['25', '--iterations=25']],
])->throws(InvalidArgumentException::class);

it('calculates percentiles, IQR, trimmed mean and outliers deterministically', function (): void {
    $odd = BootStatistics::summarize([1.0, 2.0, 3.0, 4.0, 100.0]);
    $even = BootStatistics::summarize([4.0, 1.0, 3.0, 2.0]);

    expect($odd)
        ->toMatchArray([
            'p50' => 3.0,
            'p75' => 4.0,
            'p95' => 80.8,
            'iqr' => 2.0,
            'trimmed_mean' => 22.0,
            'outliers' => [100.0],
            'samples' => [1.0, 2.0, 3.0, 4.0, 100.0],
        ])
        ->and($even['p50'])->toBe(2.5)
        ->and($even['p75'])->toBe(3.25);
});

it('keeps screenshot fixtures out of production profiles', function (): void {
    expect(BootProfiles::providers('full'))->toContain(ScreenshotWorkbenchServiceProvider::class)
        ->and(BootProfiles::providers('production'))->not->toContain(ScreenshotWorkbenchServiceProvider::class)
        ->and(BootProfiles::providers('public'))->not->toContain(
            AdminServiceProvider::class,
            MarketplaceServiceProvider::class,
        );
});

it('reports in-process framework boot as the primary benchmark sample', function (): void {
    $result = new BootBenchmark(dirname(__DIR__, 2))->run(new BootBenchmarkOptions(
        profile: 'public',
        cache: 'optimized',
        iterations: 3,
        warmups: 1,
        format: 'json',
        profiling: true,
    ));

    expect($result['statistics_ms']['p50'])
        ->toBe($result['profiling_ms']['framework_p50'])
        ->and($result['profiling_ms']['process_p50'])
        ->toBeGreaterThanOrEqual($result['profiling_ms']['framework_p50']);
});
