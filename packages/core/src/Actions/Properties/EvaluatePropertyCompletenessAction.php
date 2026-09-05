<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Data\Properties\PropertyCompletenessData;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Evaluates a page's property values against its effective definitions'
 * `required` levels. `contract` gaps are reported but never block anything —
 * they mark the page agent-layer-incomplete, consumed by the Phase 2
 * agent-schema report. `publish` gaps are reported so
 * {@see TransitionPublicationAction} can hard-gate.
 *
 * Presence is "at least one value row exists for this definition" — a
 * deliberate simplification for Phase 1: per-translation completeness for
 * `localised` definitions (e.g. complete in English but not French) is a
 * Phase 2/3 refinement of the agent-schema report, not part of this gate.
 */
final class EvaluatePropertyCompletenessAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page): PropertyCompletenessData
    {
        $effectiveDefinitions = ResolveEffectiveDefinitionsAction::run($page);
        $requiredDefinitions = $effectiveDefinitions->filter(
            static fn ($definition): bool => $definition->requirement !== PropertyRequirement::None,
        );

        if ($requiredDefinitions->isEmpty()) {
            return new PropertyCompletenessData(missingPublishRequired: [], missingContractRequired: []);
        }

        $definitionIdsWithValues = PagePropertyValue::query()
            ->where('page_id', $page->id)
            ->whereIn('property_definition_id', $requiredDefinitions->pluck('definitionId')->all())
            ->pluck('property_definition_id')
            ->unique()
            ->all();

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
