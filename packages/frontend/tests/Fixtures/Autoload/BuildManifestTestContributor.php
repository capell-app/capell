<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures\Autoload;

use Capell\Frontend\Contracts\FrontendAssetContributor;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetRequirementData;

final class BuildManifestTestContributor implements FrontendAssetContributor
{
    public function requirements(FrontendAssetContextData $context): array
    {
        return [
            new FrontendAssetRequirementData(
                handle: 'app-css',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'resources/css/app.css',
                buildPath: 'build',
            ),
            new FrontendAssetRequirementData(
                handle: 'app-css',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'resources/css/app.css',
                buildPath: 'build',
            ),
            new FrontendAssetRequirementData(
                handle: 'app-js',
                kind: FrontendAssetRequirementData::KIND_JS,
                source: 'resources/js/app.js',
                buildPath: 'build',
            ),
            new FrontendAssetRequirementData(
                handle: 'inline-config',
                kind: FrontendAssetRequirementData::KIND_INLINE,
                source: 'window.test = true;',
            ),
            new FrontendAssetRequirementData(
                handle: 'font-preload',
                kind: FrontendAssetRequirementData::KIND_PRELOAD,
                source: '/fonts/app.woff2',
            ),
        ];
    }
}
