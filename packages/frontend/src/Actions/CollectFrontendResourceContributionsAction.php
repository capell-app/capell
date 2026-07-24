<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Frontend\Actions\Performance\RecordManifestRenderContributionAction;
use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Illuminate\Contracts\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CollectFrontendResourceContributionsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Application $application) {}

    /** @return array<int, FrontendResourceContributionData> */
    public function handle(FrontendResourceContextData $context, array $widgetResourceUsages = []): array
    {
        return collect($this->application->tagged(FrontendResourceContributor::TAG))
            ->filter(static fn (mixed $contributor): bool => $contributor instanceof FrontendResourceContributor)
            ->flatMap(fn (FrontendResourceContributor $contributor): array => $this->resources($contributor, $context))
            ->merge(BuildSelectedFrontendResourceContributionsAction::run($context, $widgetResourceUsages))
            ->filter(static fn (mixed $contribution): bool => $contribution instanceof FrontendResourceContributionData)
            ->values()
            ->all();
    }

    /** @return list<FrontendResourceContributionData> */
    private function resources(
        FrontendResourceContributor $contributor,
        FrontendResourceContextData $context,
    ): array {
        $startedAt = microtime(true);
        $resources = $contributor->resources($context);
        $elapsedMilliseconds = (microtime(true) - $startedAt) * 1000;
        $typedResources = array_values(array_filter(
            $resources,
            static fn (mixed $resource): bool => $resource instanceof FrontendResourceContributionData,
        ));

        if ($typedResources === []) {
            return $resources;
        }

        foreach (resolve(CapellPackageRegistry::class)->all() as $manifest) {
            foreach ($manifest->contributes as $contribution) {
                if ($contribution->type !== ExtensionContributionType::Asset
                    || $contribution->class !== $contributor::class) {
                    continue;
                }

                $surface = $contribution->metadata['surface'] ?? null;

                if ((is_string($surface) && $surface !== 'frontend')
                    || (! is_string($surface) && ! in_array('frontend', $manifest->surfaces, true))) {
                    continue;
                }

                RecordManifestRenderContributionAction::run(
                    packageName: $manifest->name,
                    contributionType: $contribution->type->value,
                    contributionClass: $contribution->class,
                    elapsedMilliseconds: $elapsedMilliseconds,
                    cacheSafe: $manifest->performance->cacheSafety->cacheable,
                );
            }
        }

        return $resources;
    }
}
