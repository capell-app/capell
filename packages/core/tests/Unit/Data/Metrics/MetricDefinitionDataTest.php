<?php

declare(strict_types=1);

use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricSampleData;
use Capell\Core\Enums\MetricUnitEnum;
use Carbon\CarbonImmutable;

it('serialises a metric definition with snake_case keys', function (): void {
    $definition = new MetricDefinitionData(
        key: 'traffic.page_views',
        label: 'Page views',
        unit: MetricUnitEnum::Count,
        package: 'capell-app/insights',
    );

    expect($definition->toArray())->toBe([
        'key' => 'traffic.page_views',
        'label' => 'Page views',
        'unit' => 'count',
        'package' => 'capell-app/insights',
        'description' => '',
    ]);
});

it('defaults a metric sample scope to the empty string', function (): void {
    $sample = new MetricSampleData(
        metric: 'traffic.page_views',
        day: CarbonImmutable::parse('2026-07-21'),
        value: 42.0,
    );

    expect($sample->scope)->toBe('');
    expect($sample->day->toDateString())->toBe('2026-07-21');
});
