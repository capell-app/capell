<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricSampleData extends Data
{
    public function __construct(
        public readonly MetricIdentityData $identity,
        public readonly string $definitionHash,
        public readonly string $day,
        public readonly MetricScopeData $scope,
        public readonly MetricRepresentationData $representation,
        public readonly MetricValueData $value,
    ) {
        $this->value->assertMatches($this->representation);

        if (preg_match('/\A[a-f0-9]{64}\z/', $this->definitionHash) !== 1) {
            throw new InvalidArgumentException('Metric sample definition hash must be SHA-256.');
        }

        $parsedDay = DateTimeImmutable::createFromFormat('!Y-m-d', $this->day);

        if ($parsedDay === false || $parsedDay->format('Y-m-d') !== $this->day) {
            throw new InvalidArgumentException('Metric sample day must use YYYY-MM-DD.');
        }
    }

    /**
     * @return array{identity: array<string, mixed>, definition_hash: string, day: string, scope: array<string, mixed>, representation: array<string, mixed>, value: array<string, mixed>}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->toArray(),
            'definition_hash' => $this->definitionHash,
            'day' => $this->day,
            'scope' => $this->scope->toArray(),
            'representation' => $this->representation->toArray(),
            'value' => $this->value->toArray(),
        ];
    }
}
