<?php

declare(strict_types=1);

namespace Capell\Marketplace\Contracts;

use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;

interface MarketplaceComposerChangePublisher
{
    public const string TAG = 'capell.marketplace.composer-change-publisher';

    public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData;
}
