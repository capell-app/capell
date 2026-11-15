<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Capell\Core\Enums\ExtensionContributionType;
use Override;
use Spatie\LaravelData\Data;

/** @api */
final class ExtensionContributionTraceabilityData extends Data
{
    /**
     * @param  list<string>  $deferredContributions
     * @param  array<string, list<string|array<string, string>>>  $runtimeIntegrations
     * @param  list<ExtensionContributionTraceabilityEntryData>  $contributions  Runtime receipt identities the package registers without a class-backed contribution.
     */
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
                $normalisedValues = [];
                foreach ($values as $value) {
                    if (is_string($value)) {
                        $normalisedValues[] = $value;

                        continue;
                    }

                    if (is_array($value)
                        && is_string($value['event'] ?? null)
                        && is_string($value['listener'] ?? null)) {
                        $normalisedValues[] = [
                            'event' => $value['event'],
                            'listener' => $value['listener'],
                        ];
                    }
                }

                $integrations[$key] = $normalisedValues;
            }
        }

        $contributions = [];
        foreach (($data['contributions'] ?? []) as $entry) {
            if (is_array($entry)
                && is_string($entry['type'] ?? null)
                && ExtensionContributionType::tryFrom($entry['type']) instanceof ExtensionContributionType
                && is_string($entry['key'] ?? null)
                && $entry['key'] !== '') {
                $contributions[] = ExtensionContributionTraceabilityEntryData::fromArray($entry);
            }
        }

        return new self(
            deferredContributions: array_values(array_filter($data['deferredContributions'] ?? [], is_string(...))),
            runtimeIntegrations: $integrations,
            contributions: $contributions,
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        $data = [
            'deferredContributions' => $this->deferredContributions,
            'runtimeIntegrations' => $this->runtimeIntegrations,
        ];

        if ($this->contributions !== []) {
            $data['contributions'] = array_map(
                static fn (ExtensionContributionTraceabilityEntryData $entry): array => $entry->toArray(),
                $this->contributions,
            );
        }

        return $data;
    }
}
