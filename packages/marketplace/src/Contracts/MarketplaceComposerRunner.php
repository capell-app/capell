<?php

declare(strict_types=1);

namespace Capell\Marketplace\Contracts;

use Capell\Marketplace\Data\MarketplaceComposerResultData;

interface MarketplaceComposerRunner
{
    public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData;
}
