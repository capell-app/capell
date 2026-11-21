<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Contracts\Rendering\FrontendResourceContributor;
use Capell\Core\Data\Rendering\Assets\FrontendResourceContributionData;
use Capell\Core\Data\Rendering\Assets\FrontendResourceData;
use Capell\Core\Data\Rendering\FrontendResourceContextData;
use Capell\Core\Models\Page;
use Capell\Frontend\Data\Assets\ViteResourceSourceData;
use Capell\Frontend\Providers\FrontendServiceProvider;

final class AgentBridgeResourceContributor implements FrontendResourceContributor
{
    public function resources(FrontendResourceContextData $context): array
    {
        if (! $context->page instanceof Page) {
            return [];
        }

        return [new FrontendResourceContributionData(FrontendResourceData::moduleScript(
            handle: 'capell-app/frontend:agent-bridge',
            package: FrontendServiceProvider::$packageName,
            source: new ViteResourceSourceData('resources/js/agent-bridge.js', 'vendor/capell-frontend'),
        ))];
    }
}
