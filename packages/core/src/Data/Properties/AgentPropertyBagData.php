<?php

declare(strict_types=1);

namespace Capell\Core\Data\Properties;

use Capell\Core\Enums\PropertyType;
use Spatie\LaravelData\Data;

/**
 * The complete set of agent-visible property values resolved for one page
 * (own values merged with inherited term-carried values), ready to project
 * into schema.org JSON-LD (Phase 2) or a plain API payload.
 */
final class AgentPropertyBagData extends Data
{
    /**
     * @param  list<AgentPropertyEntryData>  $entries
     */
    public function __construct(
        public array $entries,
    ) {}

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Project entries into schema.org-shaped values, keyed by the term after
     * `schema:` (e.g. `schema:price` becomes `price`). Entries with no
     * `semantic` mapping use their Capell-namespaced qualified key instead
     * (`capell:<set>.<property>`), so nothing is silently dropped.
     *
     * @return array<string, mixed>
     */
    public function toSchemaOrgProperties(): array
    {
        $properties = [];

        foreach ($this->entries as $entry) {
            $key = $entry->semantic !== null && str_starts_with($entry->semantic, 'schema:')
                ? substr($entry->semantic, strlen('schema:'))
                : 'capell:' . $entry->qualifiedKey;

            $properties[$key] = $this->projectValue($entry);
        }

        return $properties;
    }

    private function projectValue(AgentPropertyEntryData $entry): mixed
    {
        return match ($entry->type) {
            PropertyType::Money => [
                '@type' => 'PriceSpecification',
                'price' => $entry->value,
                'priceCurrency' => $entry->currency,
            ],
            PropertyType::Dimension, PropertyType::Duration => [
                '@type' => 'QuantitativeValue',
                'value' => $entry->value,
                'unitCode' => $entry->unit,
            ],
            default => $entry->value,
        };
    }
}
