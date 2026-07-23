<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\Metrics\MetricReaderType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricReadContextData extends Data
{
    public function __construct(
        public readonly MetricReaderType $readerType,
        public readonly MetricScopeData $scope,
        public readonly CarbonImmutable $requestedAt,
        public readonly string $purpose,
        public readonly ?string $readerIdentifier = null,
    ) {
        throw_if(($this->readerType === MetricReaderType::Anonymous) !== ($this->readerIdentifier === null), InvalidArgumentException::class, 'Only anonymous metric readers omit a reader identifier.');

        throw_if($this->readerIdentifier !== null && trim($this->readerIdentifier) === '', InvalidArgumentException::class, 'Identified metric readers require a non-empty identifier.');

        throw_if(trim($this->purpose) === '', InvalidArgumentException::class, 'Metric read context requires an explicit purpose.');
    }

    /**
     * @return array{reader_type: string, reader_identifier: string|null, scope: array<string, mixed>, requested_at: string, purpose: string}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'reader_type' => $this->readerType->value,
            'reader_identifier' => $this->readerIdentifier,
            'scope' => $this->scope->toArray(),
            'requested_at' => $this->requestedAt->toIso8601String(),
            'purpose' => $this->purpose,
        ];
    }
}
