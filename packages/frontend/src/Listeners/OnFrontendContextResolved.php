<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Facades\CapellCore;
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
        $elapsedMilliseconds = $this->elapsedMilliseconds();

        foreach (resolve(CapellPackageRegistry::class)->all() as $manifest) {
            foreach ($manifest->contributes as $contribution) {
                if (! in_array($contribution->type, [
                    ExtensionContributionType::PageType,
                    ExtensionContributionType::PageVariation,
                ], true)) {
                    continue;
                }

                $surface = $contribution->metadata['surface'] ?? null;

                if ((is_string($surface) && $surface !== '' && $surface !== 'frontend')
                    || (! is_string($surface) && ! in_array('frontend', $manifest->surfaces, true))) {
                    continue;
                }

                $modelClass = $contribution->metadata['modelClass'] ?? null;

                if (is_string($modelClass) && $modelClass !== '' && ! $page instanceof $modelClass) {
                    continue;
                }

                RecordExtensionRenderContributionAction::run(
                    packageName: $manifest->name,
                    surface: 'frontend',
                    contributionType: $contribution->type->value,
                    contributionClass: $contribution->class,
                    elapsedMilliseconds: $elapsedMilliseconds,
                    frontendRenderBudgetMs: $manifest->performance->frontendRenderBudgetMs,
                    cacheTags: $manifest->performance->cacheTags,
                    cacheable: $manifest->performance->cacheSafety->cacheable,
                    sensitiveOutput: $manifest->performance->cacheSafety->sensitiveOutput,
                    variesBy: $manifest->performance->cacheSafety->variesBy,
                );
            }
        }
    }

    private function elapsedMilliseconds(): float
    {
        $startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        return max(0, (microtime(true) - $startedAt) * 1000);
    }
}
