<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\MetricUnitEnum;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricDefinitionData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public MetricUnitEnum $unit,
        public string $package = '',
        public string $description = '',
    ) {}
}
