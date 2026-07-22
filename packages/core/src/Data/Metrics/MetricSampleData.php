<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricSampleData extends Data
{
    public function __construct(
        public string $metric,
        public CarbonImmutable $day,
        public float $value,
        public string $scope = '',
    ) {}
}
