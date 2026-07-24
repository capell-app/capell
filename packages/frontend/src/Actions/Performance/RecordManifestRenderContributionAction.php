<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions\Performance;

use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RecordManifestRenderContributionAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly CapellPackageRegistry $packageRegistry,
    ) {}

    public function handle(
        string $packageName,
        string $contributionType,
        ?string $contributionClass,
        float $elapsedMilliseconds,
        bool $cacheSafe = true,
    ): void {
        $manifest = $this->packageRegistry->get($packageName);

        if (! $manifest instanceof CapellManifestData) {
            RecordExtensionRenderContributionAction::run(
                packageName: $packageName,
                surface: 'frontend',
                contributionType: $contributionType,
                contributionClass: $contributionClass,
                elapsedMilliseconds: $elapsedMilliseconds,
                frontendRenderBudgetMs: 0,
                cacheTags: [],
                cacheable: false,
                sensitiveOutput: true,
                variesBy: [],
            );

            return;
        }

        RecordExtensionRenderContributionAction::run(
            packageName: $manifest->name,
            surface: 'frontend',
            contributionType: $contributionType,
            contributionClass: $contributionClass,
            elapsedMilliseconds: $elapsedMilliseconds,
            frontendRenderBudgetMs: $manifest->performance->frontendRenderBudgetMs,
            cacheTags: $manifest->performance->cacheTags,
            cacheable: $manifest->performance->cacheSafety->cacheable && $cacheSafe,
            sensitiveOutput: $manifest->performance->cacheSafety->sensitiveOutput,
            variesBy: $manifest->performance->cacheSafety->variesBy,
        );
    }
}
