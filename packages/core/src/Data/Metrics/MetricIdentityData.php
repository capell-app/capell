<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricIdentityData extends Data
{
    public function __construct(
        public readonly string $ownerPackage,
        public readonly string $collectorKey,
        public readonly string $metricKey,
    ) {
        throw_if(preg_match('/\A[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*\z/', $this->ownerPackage) !== 1, InvalidArgumentException::class, 'Metric owner package must be a Composer package name.');

        foreach ([$this->collectorKey, $this->metricKey] as $identifier) {
            throw_if(preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $identifier) !== 1, InvalidArgumentException::class, 'Metric identity keys must be stable lowercase identifiers.');
        }
    }

    public function key(): string
    {
        return $this->ownerPackage . ':' . $this->collectorKey . ':' . $this->metricKey;
    }

    /** @return array{owner_package: string, collector_key: string, metric_key: string} */
    #[Override]
    public function toArray(): array
    {
        return [
            'owner_package' => $this->ownerPackage,
            'collector_key' => $this->collectorKey,
            'metric_key' => $this->metricKey,
        ];
    }
}
