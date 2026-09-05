<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\AgentPropertyBagData;
use Capell\Core\Data\Properties\AgentPropertyEntryData;
use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Concerns\HasPublishDates;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Resolves the complete agent-visible property bag for a page: its own
 * published-visible values, merged with values inherited from its assigned
 * terms.
 *
 * Visibility gate: returns an empty bag entirely for a page that is not
 * currently in a published visibility window ({@see HasPublishDates}) —
 * property values are single-copy, so "published" is checked against the
 * page itself, not a stored per-value state (see the CAP-0460 Task 0 note).
 *
 * Collision rules (locked in the spec): a page-level value always wins over
 * any term-carried value for the same property. Among terms, the term whose
 * taxonomy has the lowest `position`, then the lowest assignment `position`
 * within that taxonomy, wins — first writer in that order, deterministic.
 *
 * Localisation: a `localised` definition resolves the given language's
 * translation-scoped value first, falling back to the page-level
 * (translation_id null) value when no language-specific value exists.
 */
final class ResolveAgentPropertyValuesAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page, ?Language $language = null): AgentPropertyBagData
    {
        if ($page->publishVisibilityState() !== PublishVisibilityStateEnum::published) {
            return new AgentPropertyBagData(entries: []);
        }

        $definitions = ResolveAgentPropertyDefinitionsAction::run($page)
            ->filter(static fn (EffectivePropertyDefinitionData $definition): bool => $definition->agentVisible)
            ->values();

        if ($definitions->isEmpty()) {
            return new AgentPropertyBagData(entries: []);
        }

        $translationId = $language instanceof Language
            ? $page->translations()->where('language_id', $language->id)->value('id')
            : null;

        $pageValuesByDefinition = PagePropertyValue::query()
            ->where('page_id', $page->id)
            ->where('site_id', $page->site_id)
            ->whereIn('property_definition_id', $definitions->pluck('definitionId')->all())
            ->get()
            ->groupBy('property_definition_id');

        $termValuesByDefinition = $this->orderedTermPropertyValues($page, $definitions);

        $entries = [];

        foreach ($definitions as $definition) {
            $rows = $pageValuesByDefinition->get($definition->definitionId, new Collection);

            if ($definition->localised && $rows->isNotEmpty()) {
                $localised = $rows->where('translation_id', $translationId);
                $rows = $localised->isNotEmpty() ? $localised : $rows->where('translation_id', null);
            }

            if ($rows->isNotEmpty()) {
                $orderedRows = $rows->sortBy([
                    ['position', 'asc'],
                    ['id', 'asc'],
                ])->values();

                if (! $definition->multiple) {
                    $orderedRows = $orderedRows->take(1);
                }

                foreach ($orderedRows as $row) {
                    $entries[] = $this->entryFromPageValue($definition, $row);
                }

                continue;
            }

            $termValues = $termValuesByDefinition->get($definition->definitionId, new Collection);

            if ($termValues instanceof Collection) {
                foreach ($termValues as $termValue) {
                    $entries[] = $this->entryFromTermValue($definition, $termValue);

                    if (! $definition->multiple) {
                        break;
                    }
                }
            }
        }

        return new AgentPropertyBagData(entries: $entries);
    }

    /**
     * The values from the first term providing each definition, in
     * taxonomy-position then assignment-position order. Multiple values from
     * that winning term remain ordered by their value position.
     *
     * @param  Collection<int, EffectivePropertyDefinitionData>  $definitions
     * @return Collection<int, Collection<int, TermPropertyValue>>
     */
    private function orderedTermPropertyValues(Page $page, Collection $definitions): Collection
    {
        $orderedTerms = $page->terms()
            ->whereHas('taxonomy', static fn (Builder $query): Builder => $query->where('site_id', $page->site_id))
            ->with(['taxonomy', 'propertyValues'])
            ->get()
            ->sortBy([
                ['taxonomy.position', 'asc'],
                ['pivot.position', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $definitionIds = $definitions->pluck('definitionId')->all();
        $resolved = new Collection;

        /** @var Term $term */
        foreach ($orderedTerms as $term) {
            $valuesByDefinition = $term->propertyValues
                ->filter(static fn (TermPropertyValue $value): bool => in_array($value->property_definition_id, $definitionIds, true))
                ->groupBy('property_definition_id');

            foreach ($valuesByDefinition as $definitionId => $values) {
                if (! $resolved->has($definitionId)) {
                    $resolved->put($definitionId, $values->sortBy([
                        ['position', 'asc'],
                        ['id', 'asc'],
                    ])->values());
                }
            }
        }

        return $resolved;
    }

    private function entryFromPageValue(EffectivePropertyDefinitionData $definition, PagePropertyValue $value): AgentPropertyEntryData
    {
        return new AgentPropertyEntryData(
            qualifiedKey: $definition->qualifiedKey(),
            semantic: $definition->semantic,
            type: $definition->type,
            value: $this->extractScalar($definition, $value->value_text, $value->value_number, $value->value_boolean, $value->value_datetime),
            currency: $value->currency,
            unit: $value->unit,
            position: $value->position,
            referenceId: $this->referenceId($definition->type, $value),
        );
    }

    private function entryFromTermValue(EffectivePropertyDefinitionData $definition, TermPropertyValue $value): AgentPropertyEntryData
    {
        return new AgentPropertyEntryData(
            qualifiedKey: $definition->qualifiedKey(),
            semantic: $definition->semantic,
            type: $definition->type,
            value: $this->extractScalar($definition, $value->value_text, $value->value_number, $value->value_boolean, $value->value_datetime),
            currency: $value->currency,
            unit: $value->unit,
            position: $value->position,
            referenceId: $this->referenceId($definition->type, $value),
        );
    }

    private function extractScalar(
        EffectivePropertyDefinitionData $definition,
        ?string $valueText,
        int|float|string|null $valueNumber,
        ?bool $valueBoolean,
        mixed $valueDatetime,
    ): mixed {
        return match (true) {
            $definition->type->isNumeric() => $valueNumber !== null ? (float) $valueNumber : null,
            $definition->type->isBoolean() => $valueBoolean,
            $definition->type->isTemporal() => $valueDatetime,
            default => $valueText,
        };
    }

    private function referenceId(PropertyType $type, PagePropertyValue|TermPropertyValue $value): ?int
    {
        if (! $type->isReference()) {
            return null;
        }

        $reference = match ($type) {
            PropertyType::TermReference => $value instanceof PagePropertyValue ? $value->term_id : $value->referenced_term_id,
            PropertyType::EntryReference => $value->referenced_page_id,
            PropertyType::Media => $value->media_id,
            default => null,
        };

        return is_numeric($reference) ? (int) $reference : null;
    }
}
