<?php

declare(strict_types=1);

namespace Capell\Core\Facades;

use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Support\Metrics\MetricsManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static MetricsManager register(MetricDefinitionData $definition)
 * @method static void increment(string $metric, int $sampleEvery = 1, ?MetricScopeData $scope = null, ?CarbonImmutable $occurredAt = null)
 * @method static void record(string $metric, int $value, int $sampleEvery = 1, ?MetricScopeData $scope = null, ?CarbonImmutable $occurredAt = null)
 *
 * @mixin MetricsManager
 */
final class Metrics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MetricsManager::class;
    }
}
