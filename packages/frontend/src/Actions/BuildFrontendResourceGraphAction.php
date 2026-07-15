<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\ResolvedFrontendResourceData;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildFrontendResourceGraphAction
{
    use AsObject;

    /** @return array<string, mixed> */
    public function handle(FrontendResourcePlanData $plan): array
    {
        return [
            'fingerprint' => $plan->fingerprint,
            'assets' => array_map(static fn (ResolvedFrontendResourceData $resource): array => [
                'handle' => $resource->handle,
                'package' => $resource->package,
                'token' => $resource->token,
                'source' => $resource->url ?? '[inline]',
                'kind' => $resource->kind->value,
                'placement' => $resource->placement->value,
                'dependencies' => $resource->dependsOn,
                'integrity' => $resource->integrity,
                'reasons' => [],
            ], [...$plan->headResources, ...$plan->bodyEndResources]),
            'lazyActivations' => $plan->lazyActivationGraphs,
            'hints' => $plan->hints,
            'aliases' => $plan->aliases,
            'diagnostics' => $plan->diagnostics,
            'cspOrigins' => $plan->cspOrigins,
        ];
    }
}
