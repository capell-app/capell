<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Contracts\Rendering\FrontendResourceContributor;
use Capell\Core\Data\Rendering\Assets\FrontendResourceContributionData;
use Capell\Core\Data\Rendering\Assets\FrontendResourceData;
use Capell\Core\Data\Rendering\FrontendResourceContextData;
use Capell\Frontend\Data\Assets\ViteResourceSourceData;
use Capell\Frontend\Providers\FrontendServiceProvider;

final class CoreFrontendRuntimeContributor implements FrontendResourceContributor
{
    public function resources(FrontendResourceContextData $context): array
    {
        if (! $context->runtime->usesAlpine && ! $context->runtime->usesIslands && ! $context->runtime->usesLivewire) {
            return [];
        }

        return [new FrontendResourceContributionData(FrontendResourceData::moduleScript(
            handle: 'capell-app/frontend:runtime',
            package: FrontendServiceProvider::$packageName,
            source: new ViteResourceSourceData('resources/js/capell-frontend.js', 'vendor/capell-frontend'),
        ))];
    }
}
