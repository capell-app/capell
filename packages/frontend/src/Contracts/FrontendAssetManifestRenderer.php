<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Illuminate\Support\HtmlString;

interface FrontendAssetManifestRenderer
{
    public function render(FrontendAssetManifestData $manifest, ?FrontendAssetContextData $context = null): HtmlString;
}
