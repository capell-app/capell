<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use DateTimeInterface;
use Illuminate\Support\Collection;

final class AgentAdminPropertyValuePresenter
{
    /** @return list<array<string, mixed>> */
    public function page(Page $page): array
    {
        $definitions = ResolveEffectiveDefinitionsAction::run($page)->keyBy('definitionId');
        $values = PagePropertyValue::query()
            ->where('site_id', $page->site_id)
            ->where('page_id', $page->id)
            ->orderBy('property_definition_id')
            ->orderBy('translation_id')
            ->orderBy('position')
            ->get();

        $presented = [];
        foreach ($values as $value) {
            $data = $this->pageValue($value, $definitions);
            if ($data !== null) {
                $presented[] = $data;
            }
        }

        return $presented;
    }

    /** @return list<array<string, mixed>> */
    public function term(Term $term): array
    {
        /** @var Collection<int, TermPropertyValue> $values */
        $values = $term->propertyValues;
        $presented = [];
        foreach ($values as $value) {
            $data = $this->termValue($value);
            if ($data !== null) {
                $presented[] = $data;
            }
        }

        return $presented;
    }

    /** @return array<string, mixed> */
    public function valueData(EffectivePropertyDefinitionData $definition, mixed $value, ?int $translationId, int $position, ?string $currency, ?string $unit): array
    {
        return [
            'key' => $definition->qualifiedKey(),
            'type' => $definition->type->value,
            'semantic' => $definition->semantic,
            'value' => $value,
            'currency' => $currency,
            'unit' => $unit,
            'position' => $position,
            'translation_id' => $translationId,
        ];
    }

    /**
     * @param  Collection<int, EffectivePropertyDefinitionData>  $definitions
     * @return array<string, mixed>|null
     */
    private function pageValue(PagePropertyValue $value, Collection $definitions): ?array
    {
        $definition = $definitions->get($value->property_definition_id);

        if (! $definition instanceof EffectivePropertyDefinitionData) {
            return null;
        }

        return $this->valueData(
            definition: $definition,
            value: $this->extract($value, $definition),
            translationId: $value->translation_id,
            position: $value->position,
            currency: $value->currency,
            unit: $value->unit,
        );
    }

    /** @return array<string, mixed>|null */
    private function termValue(TermPropertyValue $value): ?array
    {
        $definition = $value->propertyDefinition;

        if (! $definition instanceof PropertyDefinition) {
            return null;
        }

        return [
            'key' => $definition->qualifiedKey(),
            'type' => $definition->type->value,
            'semantic' => $definition->semantic,
            'value' => $this->extract($value, $definition),
            'currency' => $value->currency,
            'unit' => $value->unit,
            'position' => $value->position,
        ];
    }

    private function extract(PagePropertyValue|TermPropertyValue $value, EffectivePropertyDefinitionData|PropertyDefinition $definition): mixed
    {
        return match (true) {
            $definition->type->isNumeric() => $value->value_number !== null ? (float) $value->value_number : null,
            $definition->type->isBoolean() => $value->value_boolean,
            $definition->type->isTemporal() => $value->value_datetime instanceof DateTimeInterface
                ? $value->value_datetime->format(DateTimeInterface::ATOM)
                : $value->value_datetime,
            $definition->type->isReference() && $value instanceof PagePropertyValue => match ($definition->type->value) {
                'term_reference' => $value->term_id,
                'entry_reference' => $value->referenced_page_id,
                'media' => $value->media_id,
                default => null,
            },
            $value instanceof TermPropertyValue && $definition->type->isReference() => match ($definition->type->value) {
                'term_reference' => $value->referenced_term_id,
                'entry_reference' => $value->referenced_page_id,
                'media' => $value->media_id,
                default => null,
            },
            default => $value->value_text,
        };
    }
}
