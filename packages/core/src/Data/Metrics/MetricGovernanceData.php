<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricVisibility;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricGovernanceData extends Data
{
    public function __construct(
        public readonly MetricSource $source,
        public readonly string $authoritativeSourceKey,
        public readonly MetricSensitivity $sensitivity,
        public readonly MetricVisibility $visibility,
    ) {
        throw_if(preg_match('/\A[a-z][a-z0-9]*(?:[._:-][a-z0-9]+)*\z/', $this->authoritativeSourceKey) !== 1, InvalidArgumentException::class, 'Metric authoritative source key must be a stable lowercase identifier.');
    }

    /** @return array{source: string, authoritative_source_key: string, sensitivity: string, visibility: string} */
    #[Override]
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'authoritative_source_key' => $this->authoritativeSourceKey,
            'sensitivity' => $this->sensitivity->value,
            'visibility' => $this->visibility->value,
        ];
    }
}
