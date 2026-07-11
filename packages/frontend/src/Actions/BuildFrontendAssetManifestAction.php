<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Contracts\FrontendAssetContributor;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildFrontendAssetManifestAction
{
    use AsObject;

    public function __construct(
        private readonly Application $application,
    ) {}

    public function handle(FrontendAssetContextData $context): FrontendAssetManifestData
    {
        /** @var Collection<int, FrontendAssetRequirementData> $requirements */
        $requirements = collect($this->application->tagged(FrontendAssetContributor::TAG))
            ->filter(fn (mixed $contributor): bool => $contributor instanceof FrontendAssetContributor)
            ->flatMap(fn (FrontendAssetContributor $contributor): array => $contributor->requirements($context))
            ->merge(BuildSelectedFrontendResourceRequirementsAction::run($context))
            ->filter(fn (mixed $requirement): bool => $requirement instanceof FrontendAssetRequirementData)
            ->values();

        $manifestData = new FrontendAssetManifestData(
            css: [],
            js: [],
            inline: [],
            preloads: [],
            runtime: $context->runtime,
            rawRequirements: $requirements->all(),
        );

        /** @var Collection<int, FrontendAssetRequirementData> $dedupedRequirements */
        $dedupedRequirements = $requirements
            ->groupBy(fn (FrontendAssetRequirementData $requirement): string => $this->canonicalKey(
                $manifestData,
                $requirement,
            ))
            ->map(function (Collection $matching): FrontendAssetRequirementData {
                $requirement = $matching
                    ->sortBy(fn (FrontendAssetRequirementData $candidate): int => $this->strategyRank($candidate->loadingStrategy))
                    ->first();

                throw_unless($requirement instanceof FrontendAssetRequirementData);

                return $requirement;
            })
            ->values();
        $lazy = $dedupedRequirements
            ->filter(fn (FrontendAssetRequirementData $requirement): bool => $requirement->condition !== null)
            ->values();
        $eager = $dedupedRequirements
            ->reject(fn (FrontendAssetRequirementData $requirement): bool => $requirement->condition !== null)
            ->values();

        return new FrontendAssetManifestData(
            css: $eager->filter->isCss()->values()->all(),
            js: $eager->filter->isJavaScript()->values()->all(),
            inline: $eager->filter->isInline()->values()->all(),
            preloads: $eager->filter->isPreload()->values()->all(),
            runtime: $context->runtime,
            lazy: $lazy->all(),
            rawRequirements: $requirements->all(),
        );
    }

    private function canonicalKey(
        FrontendAssetManifestData $manifest,
        FrontendAssetRequirementData $requirement,
    ): string {
        if ($requirement->isInline()) {
            return $requirement->kind . ':' . hash('xxh128', $requirement->source);
        }

        return $requirement->kind . ':' . $manifest->resolvedAssetUrl($requirement);
    }

    private function strategyRank(PresentationLoadingStrategy $strategy): int
    {
        return match ($strategy) {
            PresentationLoadingStrategy::Eager => 0,
            PresentationLoadingStrategy::Visible => 1,
            PresentationLoadingStrategy::Idle => 2,
            PresentationLoadingStrategy::Interaction => 3,
        };
    }
}
