<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Models\Page;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Resolves definitions available to the public agent representation.
 *
 * Page-owned definitions come from the page blueprint. Assigned, same-site
 * taxonomies may add definitions for their term-carried values; those
 * definitions do not become page-writable definitions or receive blueprint
 * overrides.
 */
final class ResolveAgentPropertyDefinitionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, EffectivePropertyDefinitionData>
     */
    public function handle(Page $page): Collection
    {
        $effective = ResolveEffectiveDefinitionsAction::run($page);
        $seenDefinitionIds = $effective->pluck('definitionId')->flip();

        $terms = $page->terms()
            ->whereHas('taxonomy', static fn (Builder $query): Builder => $query->where('site_id', $page->site_id))
            ->with('taxonomy.propertySet.definitions')
            ->get()
            ->sortBy([
                ['taxonomy.position', 'asc'],
                ['pivot.position', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        foreach ($terms as $term) {
            $propertySet = $term->taxonomy?->propertySet;

            if (! $propertySet instanceof PropertySet) {
                continue;
            }

            /** @var PropertyDefinition $definition */
            foreach ($propertySet->definitions->sortBy([
                ['position', 'asc'],
                ['id', 'asc'],
            ]) as $definition) {
                if ($seenDefinitionIds->has($definition->id)) {
                    continue;
                }

                $effective->push(new EffectivePropertyDefinitionData(
                    definitionId: $definition->id,
                    propertySetId: $propertySet->id,
                    propertySetKey: $propertySet->key,
                    key: $definition->key,
                    type: $definition->type,
                    semantic: $definition->semantic,
                    requirement: $definition->requirement,
                    agentVisible: $definition->agent_visible,
                    localised: $definition->localised,
                    multiple: $definition->multiple,
                    locked: $definition->locked,
                    description: $definition->description,
                    unitConfig: $definition->unit_config,
                    position: $definition->position,
                ));
                $seenDefinitionIds->put($definition->id, true);
            }
        }

        return $effective;
    }
}
