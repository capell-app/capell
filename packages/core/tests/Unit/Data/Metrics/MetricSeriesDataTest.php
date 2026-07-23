<?php

declare(strict_types=1);

use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricPointData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSeriesData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\MetricUnitEnum;
use Carbon\CarbonImmutable;

it('summarises present points without treating missing points as zero', function (): void {
    $series = new MetricSeriesData(
        identity: new MetricIdentityData('capell-app/site-stats', 'content_totals', 'content.pages_total'),
        representation: new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
        scope: MetricScopeData::global('UTC'),
        points: [
            new MetricPointData(CarbonImmutable::parse('2026-07-20'), MetricPointState::Present, MetricValueData::integer(10)),
            new MetricPointData(CarbonImmutable::parse('2026-07-21'), MetricPointState::Missing, null),
            new MetricPointData(CarbonImmutable::parse('2026-07-22'), MetricPointState::Present, MetricValueData::integer(20)),
        ],
    );

    expect($series->total())->toBe(30.0)
        ->and($series->average())->toBe(15.0)
        ->and($series->latest())->toBe(20.0);
});

it('rejects point states that disagree with their values', function (): void {
    new MetricPointData(
        CarbonImmutable::parse('2026-07-20'),
        MetricPointState::Zero,
        MetricValueData::integer(1),
    );
})->throws(InvalidArgumentException::class, 'Metric zero state must agree with its value.');

it('rejects duplicate or unordered point days', function (): void {
    new MetricSeriesData(
        identity: new MetricIdentityData('capell-app/site-stats', 'content_totals', 'content.pages_total'),
        representation: new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
        scope: MetricScopeData::global('UTC'),
        points: [
            new MetricPointData(CarbonImmutable::parse('2026-07-21'), MetricPointState::Present, MetricValueData::integer(20)),
            new MetricPointData(CarbonImmutable::parse('2026-07-20'), MetricPointState::Present, MetricValueData::integer(10)),
        ],
    );
})->throws(InvalidArgumentException::class, 'Metric series points must use unique ascending days.');
