<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Models\Page;
use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\ViteResourceSourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
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
