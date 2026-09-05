<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\EventSourcing\Listeners\RecordPageRevision;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\Exceptions\PropertyValueValidationException;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Support\Properties\PropertyValueValidator;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Writes a page's property values.
 *
 * Values are single-copy (see the CAP-0460 Task 0 note: Core's page content
 * has no draft/published duality, so property values don't either) and take
 * effect immediately, exactly like editing a page's title or content. The
 * write rides the SAME revision-recording bridge every other page save
 * uses — {@see PageSavedAction} dispatches `PageSaved`, which
 * {@see RecordPageRevision} turns into a
 * snapshot revision via {@see PageStateSerializer}
 * (extended to capture/restore property state) — there is no bespoke
 * property-mutation event.
 *
 * A value whose definition is field-promoted ({@see PromoteBlueprintFieldAction})
 * can only be written through {@see SyncPromotedFieldValuesAction}; a direct
 * call here for a promoted property is rejected to prevent two-master drift.
 */
final class SetPagePropertyValuesAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly PropertyValueValidator $validator,
    ) {}

    /**
     * @param  list<PropertyValueData>  $values
     */
    public function handle(Page $page, array $values, bool $viaPromotedFieldSync = false): void
    {
        if ($values === []) {
            return;
        }

        $effectiveDefinitions = ResolveEffectiveDefinitionsAction::run($page);

        DB::transaction(function () use ($page, $values, $effectiveDefinitions, $viaPromotedFieldSync): void {
            $touchedDefinitionIds = [];

            foreach ($values as $value) {
                $definition = $this->validator->validate($page, $value, $effectiveDefinitions);

                if (! $viaPromotedFieldSync && $definition->promotedField !== null) {
                    throw PropertyValueValidationException::promotedPropertyDirectWrite(
                        $value->propertyKey,
                        $definition->promotedField,
                    );
                }

                $touchedDefinitionIds[$definition->definitionId] ??= [];
                $touchedDefinitionIds[$definition->definitionId][] = $value->position;

                PagePropertyValue::query()->updateOrCreate(
                    [
                        'page_id' => $page->id,
                        'property_definition_id' => $definition->definitionId,
                        'translation_id' => $value->translationId,
                        'position' => $value->position,
                    ],
                    [
                        'site_id' => $page->site_id,
                        ...$value->toColumns(),
                    ],
                );

                // A non-multiple definition holds exactly one value: drop any
                // stray positions left over from before it was written again.
                if (! $definition->multiple) {
                    PagePropertyValue::query()
                        ->where('page_id', $page->id)
                        ->where('property_definition_id', $definition->definitionId)
                        ->where('translation_id', $value->translationId)
                        ->where('position', '!=', $value->position)
                        ->delete();
                }
            }

            PageSavedAction::run($page, ['_property_keys' => array_keys($touchedDefinitionIds)]);
        });
    }
}
