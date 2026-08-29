<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Spatie\LaravelData\Data;

final class ExtensionContributionTraceabilityData extends Data
{
    /**
     * @param  list<string>  $deferredContributions
     * @param  array<string, list<string|array<string, string>>>  $runtimeIntegrations
     */
    public function __construct(
        public readonly array $deferredContributions = [],
        public readonly array $runtimeIntegrations = [],
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

        return new self(
            deferredContributions: array_values(array_filter($data['deferredContributions'] ?? [], is_string(...))),
            runtimeIntegrations: $integrations,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'deferredContributions' => $this->deferredContributions,
            'runtimeIntegrations' => $this->runtimeIntegrations,
        ];
    }
}
