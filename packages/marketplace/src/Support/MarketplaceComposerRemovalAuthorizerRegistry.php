<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Registries\TaggedProviderRegistry;
use Capell\Marketplace\Contracts\MarketplaceComposerRemovalAuthorizer;
use Illuminate\Contracts\Foundation\Application;

/** @extends TaggedProviderRegistry<MarketplaceComposerRemovalAuthorizer> */
final class MarketplaceComposerRemovalAuthorizerRegistry extends TaggedProviderRegistry
{
    public function __construct(Application $application)
    {
        parent::__construct(
            self::tagged($application, MarketplaceComposerRemovalAuthorizer::TAG),
            MarketplaceComposerRemovalAuthorizer::class,
        );
    }

    /** @return list<MarketplaceComposerRemovalAuthorizer> */
    public function all(): array
    {
        return $this->providers();
    }
}
