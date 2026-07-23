<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricSeriesData extends Data
{
    /**
     * @param  list<MetricPointData>  $points
     */
    public function __construct(
        public readonly MetricIdentityData $identity,
        public readonly MetricRepresentationData $representation,
        public readonly MetricScopeData $scope,
        public readonly array $points,
    ) {
        $previousDay = null;

        foreach ($this->points as $point) {
            throw_unless($point instanceof MetricPointData, InvalidArgumentException::class, 'Metric series points must be metric point data.');
            $point->value?->assertMatches($this->representation);

            $day = $point->day->toDateString();

            throw_if($previousDay !== null && $day <= $previousDay, InvalidArgumentException::class, 'Metric series points must use unique ascending days.');

            $previousDay = $day;
        }
    }

    public function total(): float
    {
        return array_sum(array_filter(
            array_map(static fn (MetricPointData $point): ?float => $point->numericValue(), $this->points),
            static fn (?float $value): bool => $value !== null,
        ));
    }

    public function average(): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn (MetricPointData $point): ?float => $point->numericValue(), $this->points),
            static fn (?float $value): bool => $value !== null,
        ));

        return $values === [] ? null : array_sum($values) / count($values);
    }

    public function latest(): ?float
    {
        for ($index = count($this->points) - 1; $index >= 0; $index--) {
            $value = $this->points[$index]->numericValue();

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
