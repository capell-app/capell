<?php

declare(strict_types=1);

namespace Capell\Marketplace\Contracts;

use Capell\Marketplace\Data\MarketplaceComposerResultData;

interface MarketplaceAuthenticatedComposerRunner extends MarketplaceComposerRunner
{
    /**
     * @param  array<string, mixed>  $composerAuth
     */
    public function requireWithComposerAuth(
        string $composerName,
        string $versionConstraint,
        int $timeoutSeconds,
        array $composerAuth,
    ): MarketplaceComposerResultData;
}
