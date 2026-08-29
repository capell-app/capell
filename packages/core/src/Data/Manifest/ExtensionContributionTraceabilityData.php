<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Spatie\LaravelData\Data;

final class ExtensionContributionTraceabilityData extends Data
{
    /** @param list<string> $deferredContributions @param array<string, mixed> $runtimeIntegrations @param list<ExtensionContributionTraceabilityEntryData> $contributions */
    public function __construct(
        public readonly array $deferredContributions = [],
        public readonly array $runtimeIntegrations = [],
        public readonly array $contributions = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $integrations = [];
        foreach (($data['runtimeIntegrations'] ?? []) as $key => $values) {
            if (is_string($key) && is_array($values)) {
                $integrations[$key] = $values;
            }
        }

        return new self(
            deferredContributions: array_values(array_filter($data['deferredContributions'] ?? [], is_string(...))),
            runtimeIntegrations: $integrations,
            contributions: array_map(
                ExtensionContributionTraceabilityEntryData::fromArray(...),
                array_values(array_filter($data['contributions'] ?? [], is_array(...))),
            ),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'deferredContributions' => $this->deferredContributions,
            'runtimeIntegrations' => $this->runtimeIntegrations,
            'contributions' => array_map(
                static fn (ExtensionContributionTraceabilityEntryData $entry): array => $entry->toArray(),
                $this->contributions,
            ),
        ];
    }
}
