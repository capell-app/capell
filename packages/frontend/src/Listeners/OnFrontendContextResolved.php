<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\Frontend\Enums\ListenerEnum;
use Capell\Frontend\Events\FrontendContextResolved;

final class OnFrontendContextResolved
{
    public function handle(FrontendContextResolved $event): void
    {
        $page = $event->context->page;

        if ($page instanceof Pageable) {
            CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::LayoutLoaded, $page);
        }

        $this->recordPageExtensionRenderContributions($page);
    }

    private function recordPageExtensionRenderContributions(?Pageable $page): void
    {
        if (! $page instanceof Pageable) {
            return;
        }

        /** @var list<array{CapellManifestData, ExtensionContributionData}> $matches */
        $matches = [];

        foreach (resolve(CapellPackageRegistry::class)->all() as $manifest) {
            foreach ($manifest->contributes as $contribution) {
                if (! in_array($contribution->type, [
                    ExtensionContributionType::PageType,
                    ExtensionContributionType::PageVariation,
                ], true)) {
                    continue;
                }

                $surface = $contribution->metadata['surface'] ?? null;
                if (is_string($surface) && $surface !== '' && $surface !== 'frontend') {
                    continue;
                }

                if (! is_string($surface) && ! in_array('frontend', $manifest->surfaces, true)) {
                    continue;
                }

                $modelClass = $contribution->metadata['modelClass'] ?? null;
                if (! is_string($modelClass)) {
                    continue;
                }

                if ($modelClass === '') {
                    continue;
                }

                if (! $page instanceof $modelClass) {
                    continue;
                }

                $matches[] = [$manifest, $contribution];
            }
        }

        if (count($matches) !== 1) {
            return;
        }

        [$manifest, $contribution] = $matches[0];

        RecordExtensionRenderContributionAction::run(
            packageName: $manifest->name,
            surface: 'frontend',
            contributionType: $contribution->type->value,
            contributionClass: $contribution->class,
            elapsedMilliseconds: 0,
            frontendRenderBudgetMs: $manifest->performance->frontendRenderBudgetMs,
            cacheTags: $manifest->performance->cacheTags,
            cacheable: $manifest->performance->cacheSafety->cacheable,
            sensitiveOutput: $manifest->performance->cacheSafety->sensitiveOutput,
            variesBy: $manifest->performance->cacheSafety->variesBy,
        );
    }
}
