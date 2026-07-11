<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures\Autoload;

use Capell\Frontend\Contracts\FrontendAssetContributor;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetRequirementData;

final class BuildManifestFirstDuplicateContributor implements FrontendAssetContributor
{
    public function requirements(FrontendAssetContextData $context): array
    {
        return [
            new FrontendAssetRequirementData(
                handle: 'first',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'resources/css/capell/frontend.css',
                buildPath: 'build',
            ),
        ];
    }
}
