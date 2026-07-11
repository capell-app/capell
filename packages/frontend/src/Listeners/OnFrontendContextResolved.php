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

        $this->recordExtensionRenderContributions();
    }

    private function recordExtensionRenderContributions(): void
    {
        $elapsedMilliseconds = $this->elapsedMilliseconds();

        foreach (resolve(CapellPackageRegistry::class)->all() as $manifest) {
            foreach ($manifest->contributes as $contribution) {
                if (! $this->shouldAttributeContribution($manifest, $contribution)) {
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

    private function shouldAttributeContribution(CapellManifestData $manifest, ExtensionContributionData $contribution): bool
    {
        if (! $this->targetsFrontend($manifest, $contribution)) {
            return false;
        }

        return in_array($contribution->type, [
            ExtensionContributionType::Section,
            ExtensionContributionType::PageType,
            ExtensionContributionType::PageVariation,
            ExtensionContributionType::RenderHook,
            ExtensionContributionType::Asset,
        ], true);
    }

    private function targetsFrontend(CapellManifestData $manifest, ExtensionContributionData $contribution): bool
    {
        $surface = $contribution->metadata['surface'] ?? null;

        if (is_string($surface) && $surface !== '') {
            return $surface === 'frontend';
        }

        return in_array('frontend', $manifest->surfaces, true);
    }

    private function elapsedMilliseconds(): float
    {
        $startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        return max(0, (microtime(true) - $startedAt) * 1000);
    }
}
