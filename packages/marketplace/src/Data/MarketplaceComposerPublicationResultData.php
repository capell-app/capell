<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

/** @internal */
final class MarketplaceComposerPublicationResultData extends Data
{
    public function __construct(
        public readonly ?string $pullRequestUrl = null,
        public readonly ?string $commitSha = null,
    ) {}
}
