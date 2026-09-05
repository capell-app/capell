<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PropertySet;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Merges the property sets attached to a page's blueprint with that
 * blueprint's per-attachment overrides, producing the single effective
 * definition list every write/read/curation path consults.
 *
 * A `locked` definition establishes a floor: an override can never lower its
 * `requirement` below the definition's own declared level, and can never
 * override its `agent_visible` at all.
 */
final class ResolveEffectiveDefinitionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, EffectivePropertyDefinitionData>
     */
    public function handle(Page $page): Collection
    {
        $attachments = BlueprintPropertySet::query()
            ->where('blueprint_id', $page->blueprint_id)
            ->with('propertySet.definitions')
            ->get();

        $effective = new Collection;

        foreach ($attachments as $attachment) {
            $propertySet = $attachment->propertySet;

            // Defensive, not just PHPStan appeasement: the FK is
            // constrained/cascade-deletes, so this should never happen in
            // practice, but a dangling attachment must never crash property
            // resolution for the rest of the page.
            if (! $propertySet instanceof PropertySet) {
                continue;
            }

            $overrides = $attachment->overrides ?? [];

            foreach ($propertySet->definitions as $definition) {
                /** @var array<string, mixed> $override */
                $override = $overrides[$definition->key] ?? [];

                $requirement = $definition->requirement;

                if (isset($override['requirement'])) {
                    $requested = $override['requirement'] instanceof PropertyRequirement
                        ? $override['requirement']
                        : PropertyRequirement::from((string) $override['requirement']);

                    $requirement = $definition->locked
                        ? $requested->clampedTo($definition->requirement)
                        : $requested;
                }

                $agentVisible = $definition->agent_visible;

                if (! $definition->locked && array_key_exists('agent_visible', $override)) {
                    $agentVisible = (bool) $override['agent_visible'];
                }

                $effective->push(new EffectivePropertyDefinitionData(
                    definitionId: $definition->id,
                    propertySetId: $definition->property_set_id,
                    propertySetKey: $propertySet->key,
                    key: $definition->key,
                    type: $definition->type,
                    semantic: $definition->semantic,
                    requirement: $requirement,
                    agentVisible: $agentVisible,
                    localised: $definition->localised,
                    multiple: $definition->multiple,
                    locked: $definition->locked,
                    description: $override['description'] ?? $definition->description,
                    unitConfig: $definition->unit_config,
                    position: $definition->position,
                    promotedField: $override['promoted_field'] ?? null,
                ));
            }
        }

        return $effective;
    }
}
