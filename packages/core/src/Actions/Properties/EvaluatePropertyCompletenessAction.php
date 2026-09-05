<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Data\Properties\PropertyCompletenessData;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Evaluates a page's property values against its effective definitions'
 * `required` levels. `contract` gaps are reported but never block anything —
 * they mark the page agent-layer-incomplete, consumed by the Phase 2
 * agent-schema report. `publish` gaps are reported so
 * {@see TransitionPublicationAction} can hard-gate.
 *
 * Presence is "at least one page or inherited term value row exists for this
 * definition" — a deliberate simplification for Phase 1: per-translation
 * completeness for `localised` definitions is a later refinement.
 */
final class EvaluatePropertyCompletenessAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page): PropertyCompletenessData
    {
        $effectiveDefinitions = ResolveAgentPropertyDefinitionsAction::run($page);
        $requiredDefinitions = $effectiveDefinitions->filter(
            static fn ($definition): bool => $definition->requirement !== PropertyRequirement::None,
        );

        if ($requiredDefinitions->isEmpty()) {
            return new PropertyCompletenessData(missingPublishRequired: [], missingContractRequired: []);
        }

        $definitionIdsWithValues = PagePropertyValue::query()
            ->where('page_id', $page->id)
            ->where('site_id', $page->site_id)
            ->whereIn('property_definition_id', $requiredDefinitions->pluck('definitionId')->all())
            ->pluck('property_definition_id')
            ->unique()
            ->all();

        $termIds = $page->terms()
            ->whereHas('taxonomy', static fn (Builder $query): Builder => $query->where('site_id', $page->site_id))
            ->pluck('terms.id');

        if ($termIds->isNotEmpty()) {
            $definitionIdsWithValues = array_unique([
                ...$definitionIdsWithValues,
                ...TermPropertyValue::query()
                    ->whereIn('term_id', $termIds->all())
                    ->whereIn('property_definition_id', $requiredDefinitions->pluck('definitionId')->all())
                    ->pluck('property_definition_id')
                    ->all(),
            ]);
        }

        $missingPublish = [];
        $missingContract = [];

        foreach ($requiredDefinitions as $definition) {
            if (in_array($definition->definitionId, $definitionIdsWithValues, true)) {
                continue;
            }

            if ($definition->requirement === PropertyRequirement::Publish) {
                $missingPublish[] = $definition->qualifiedKey();
            } else {
                $missingContract[] = $definition->qualifiedKey();
            }
        }

        return new PropertyCompletenessData(
            missingPublishRequired: $missingPublish,
            missingContractRequired: $missingContract,
        );
    }
}
