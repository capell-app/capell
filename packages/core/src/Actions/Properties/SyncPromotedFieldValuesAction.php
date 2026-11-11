<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Support\Properties\PropertyBreachReports;
use Capell\Core\Support\Properties\PropertyValueEquality;
use Capell\Core\Support\Properties\PropertyValueValidator;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The only path that writes a promoted property's value: reads the mapped
 * blueprint field from the page, coerces it, and writes it through
 * {@see SetPagePropertyValuesAction} with `$viaPromotedFieldSync: true`
 * (which is what makes that action accept the write — every other caller is
 * rejected for a promoted property). Invoked on every page save.
 *
 * A field whose value already matches the stored property is skipped, and a
 * value is synced only while it meets the property's rules, so the sync can
 * never fail a save that has already been written. A
 * field currently holding `null` is left alone rather than clearing the
 * property — an in-progress edit that has not reached the promoted field yet
 * should not blank out a previously-synced value.
 */
final class SyncPromotedFieldValuesAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly PropertyValueValidator $validator) {}

    /**
     * Coerce a promoted field's raw form value to the property's value shape.
     * Shared with admin form validation so constraints are checked against
     * exactly the value this sync would write.
     */
    public static function coerce(EffectivePropertyDefinitionData $definition, mixed $rawValue): mixed
    {
        return match (true) {
            $definition->type->isBoolean() => (bool) $rawValue,
            $definition->type->isNumeric() => is_numeric($rawValue) ? (float) $rawValue : null,
            default => is_scalar($rawValue) ? (string) $rawValue : null,
        };
    }

    public function handle(Page $page): void
    {
        $promoted = ResolveEffectiveDefinitionsAction::run($page)
            ->filter(static fn (EffectivePropertyDefinitionData $definition): bool => $definition->promotedField !== null);

        if ($promoted->isEmpty()) {
            return;
        }

        $values = [];
        $stored = PagePropertyValue::query()
            ->where('site_id', $page->site_id)
            ->where('page_id', $page->id)
            ->whereIn('property_definition_id', $promoted->pluck('definitionId')->all())
            ->whereNull('translation_id')
            ->where('position', 0)
            ->get()
            ->keyBy('property_definition_id');

        foreach ($promoted as $definition) {
            $rawValue = data_get($page, (string) $definition->promotedField);

            if ($rawValue === null) {
                continue;
            }

            $value = new PropertyValueData(
                propertyKey: $definition->key,
                type: $definition->type,
                value: self::coerce($definition, $rawValue),
            );

            // A value the save changes was checked before the row was written.
            // One it left as it was may predate a rule: outside production the
            // check before the write refused it, in production it is reported
            // here, once per request. Sync a value only while it meets the rules,
            // so nothing can fail after the page is written; the property
            // keeps its last good value until the field is corrected.
            if ($definition->constraints instanceof PropertyConstraintsData
                && ! $this->validator->passesConstraints($definition, $value->value, (int) $page->site_id, (int) $page->id)) {
                $this->reportBreach($page, $definition, $value);

                continue;
            }

            // An unchanged value is not re-written.
            $current = $stored->get($definition->definitionId);

            if ($current instanceof PagePropertyValue
                && PropertyValueEquality::sameColumns(PropertyValueData::fromPageValue($current, $definition->key, $definition->type)->toColumns(), $value->toColumns())) {
                continue;
            }

            $values[] = $value;
        }

        if ($values !== []) {
            SetPagePropertyValuesAction::run($page, $values, viaPromotedFieldSync: true);
        }
    }

    /**
     * Logs an unchanged promoted value that breaks its rules, once per page,
     * property and value in a request or job ({@see PropertyBreachReports}).
     */
    private function reportBreach(Page $page, EffectivePropertyDefinitionData $definition, PropertyValueData $value): void
    {
        $identity = PropertyValueEquality::identity('value_' . ($definition->type->isNumeric() ? 'number' : 'text'), $value->value);

        if (! resolve(PropertyBreachReports::class)->first((int) $page->id, $definition->definitionId, $identity)) {
            return;
        }

        Log::warning('A promoted page value breaks its property rules; the save was not blocked and the property was not updated.', [
            'property' => $definition->key,
            'site_id' => $page->site_id,
            'page_id' => $page->id,
        ]);
    }
}
