<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Data\PublicRenderDataContributionMetadataData;
use Illuminate\Database\Eloquent\Model;

/**
 * Adds one keyed, public-safe value to the hydrated page render data.
 *
 * Implementations are bound to the container and tagged with TAG by the
 * package provider. A contributor must load all of its data before Blade
 * renders and declare every model dependency that can make that data stale.
 */
interface PublicRenderDataContributor
{
    public const string TAG = 'capell.frontend.public-render-data-contributor';

    /** A globally stable key for the contributor's public data. */
    public function key(): string;

    /** Whether this contributor applies to the supplied public render context. */
    public function supports(FrontendRenderContextData $context): bool;

    public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData;

    /** Build the typed value and its cache/public delivery declarations. */
    public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData;

    /** @return list<class-string<Model>> */
    public function cacheDependencyModelTypes(): array;
}
