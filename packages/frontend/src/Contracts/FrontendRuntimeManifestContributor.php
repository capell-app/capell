<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRuntimeManifestData;

interface FrontendRuntimeManifestContributor
{
    public const string TAG = 'capell.frontend.runtime-manifest-contributor';

    public function contribute(FrontendContextReader $context, FrontendRuntimeManifestData $manifest): void;
}
