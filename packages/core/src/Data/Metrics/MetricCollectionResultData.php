<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricCollectionResultData extends Data
{
    /**
     * @param  list<MetricScopeData>  $coveredScopes
     * @param  list<MetricSampleData>  $samples
     */
    public function __construct(
        public readonly MetricCollectionStatus $status,
        public readonly string $day,
        public readonly array $coveredScopes,
        public readonly array $samples,
        public readonly ?string $sourceWatermark,
        public readonly ?string $sourceChecksum,
        public readonly ?string $reason,
    ) {
        $parsedDay = DateTimeImmutable::createFromFormat('!Y-m-d', $this->day);

        throw_if($parsedDay === false || $parsedDay->format('Y-m-d') !== $this->day, InvalidArgumentException::class, 'Metric collection day must use YYYY-MM-DD.');

        throw_if($this->status === MetricCollectionStatus::Complete
            && ($this->coveredScopes === [] || trim($this->sourceWatermark ?? '') === '' || $this->sourceChecksum === null || $this->reason !== null), InvalidArgumentException::class, 'Complete metric collections require coverage and source identity, without a failure reason.');

        throw_if($this->status !== MetricCollectionStatus::Complete && trim($this->reason ?? '') === '', InvalidArgumentException::class, 'Incomplete metric collections require a reason.');

        throw_if($this->status === MetricCollectionStatus::Failed && $this->samples !== [], InvalidArgumentException::class, 'Failed metric collections cannot contain samples.');

        throw_if($this->status === MetricCollectionStatus::Unsupported
            && ($this->coveredScopes !== [] || $this->samples !== [] || $this->sourceWatermark !== null || $this->sourceChecksum !== null), InvalidArgumentException::class, 'Unsupported metric collections may only report their reason.');

        throw_if($this->sourceChecksum !== null && preg_match('/\A[a-f0-9]{64}\z/', $this->sourceChecksum) !== 1, InvalidArgumentException::class, 'Metric source checksum must be SHA-256.');

        $scopeKeys = array_map(static fn (MetricScopeData $scope): string => $scope->key(), $this->coveredScopes);

        throw_if(count($scopeKeys) !== count(array_unique($scopeKeys)), InvalidArgumentException::class, 'Metric collection coverage cannot contain duplicate scopes.');

        $sampleKeys = [];

        foreach ($this->samples as $sample) {
            throw_if($sample->day !== $this->day || ! in_array($sample->scope->key(), $scopeKeys, true), InvalidArgumentException::class, 'Metric samples must belong to the result day and covered scopes.');

            $sampleKey = $sample->identity->key() . ':' . $sample->scope->key();

            throw_if(isset($sampleKeys[$sampleKey]), InvalidArgumentException::class, 'Metric collections cannot contain duplicate samples for an identity and scope.');

            $sampleKeys[$sampleKey] = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'day' => $this->day,
            'covered_scopes' => array_map(
                static fn (MetricScopeData $scope): array => $scope->toArray(),
                $this->coveredScopes,
            ),
            'samples' => array_map(
                static fn (MetricSampleData $sample): array => $sample->toArray(),
                $this->samples,
            ),
            'source_watermark' => $this->sourceWatermark,
            'source_checksum' => $this->sourceChecksum,
            'reason' => $this->reason,
        ];
    }
}
