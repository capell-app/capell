<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\Metrics\MetricDefinitionStatus;
use Capell\Core\Enums\Metrics\MetricScopeType;
use InvalidArgumentException;
use JsonException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricDefinitionData extends Data
{
    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $descriptions
     */
    public function __construct(
        public readonly MetricIdentityData $identity,
        public readonly MetricRepresentationData $representation,
        public readonly MetricScopeType $scopeType,
        public readonly MetricSemanticsData $semantics,
        public readonly MetricGovernanceData $governance,
        public readonly MetricDefinitionStatus $status = MetricDefinitionStatus::Active,
        public readonly ?MetricIdentityData $replaces = null,
        public readonly ?MetricIdentityData $replacedBy = null,
        public readonly array $labels = [],
        public readonly array $descriptions = [],
    ) {
        $this->semantics->assertCompatibleWith($this->representation);

        if ($this->status === MetricDefinitionStatus::Active && $this->replacedBy !== null) {
            throw new InvalidArgumentException('Only tombstoned metric definitions may point to a replacement.');
        }

        if ($this->status === MetricDefinitionStatus::Tombstoned && $this->replaces !== null) {
            throw new InvalidArgumentException('Tombstoned metric definitions cannot replace another metric.');
        }

        foreach ([$this->replaces, $this->replacedBy] as $relatedIdentity) {
            if ($relatedIdentity !== null
                && ($relatedIdentity->ownerPackage !== $this->identity->ownerPackage
                    || $relatedIdentity->collectorKey !== $this->identity->collectorKey
                    || $relatedIdentity->metricKey === $this->identity->metricKey)) {
                throw new InvalidArgumentException('Metric replacements must be distinct identities within the same owner and collector.');
            }
        }

        $this->assertTranslations($this->labels);
        $this->assertTranslations($this->descriptions);
    }

    public function retentionDays(): ?int
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function semanticPayload(): array
    {
        return [
            'schema_version' => 1,
            'identity' => $this->identity->toArray(),
            'representation' => $this->representation->toArray(),
            'scope_type' => $this->scopeType->value,
            'semantics' => $this->semantics->toArray(),
            'governance' => $this->governance->toArray(),
            'status' => $this->status->value,
            'replaces' => $this->replaces?->toArray(),
            'replaced_by' => $this->replacedBy?->toArray(),
            'retention_days' => $this->retentionDays(),
        ];
    }

    /** @throws JsonException */
    public function semanticJson(): string
    {
        return json_encode($this->semanticPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @throws JsonException */
    public function semanticHash(): string
    {
        return hash('sha256', $this->semanticJson());
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            ...$this->semanticPayload(),
            'semantic_hash' => $this->semanticHash(),
            'labels' => $this->labels,
            'descriptions' => $this->descriptions,
        ];
    }

    /** @param array<string, string> $translations */
    private function assertTranslations(array $translations): void
    {
        foreach ($translations as $language => $translation) {
            if ($language === '' || trim($translation) === '') {
                throw new InvalidArgumentException('Metric translations require a language and non-empty text.');
            }
        }
    }
}
