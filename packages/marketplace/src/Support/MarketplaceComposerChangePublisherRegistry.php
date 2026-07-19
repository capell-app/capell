<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Registries\TaggedProviderRegistry;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Illuminate\Contracts\Foundation\Application;

/** @extends TaggedProviderRegistry<MarketplaceComposerChangePublisher> */
final class MarketplaceComposerChangePublisherRegistry extends TaggedProviderRegistry
{
    public function __construct(Application $application)
    {
        parent::__construct(
            self::tagged($application, MarketplaceComposerChangePublisher::TAG),
            MarketplaceComposerChangePublisher::class,
        );
    }

    public function first(): ?MarketplaceComposerChangePublisher
    {
        return $this->providers()[0] ?? null;
    }
}
