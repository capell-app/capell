<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceComposerPublicationRequestData extends Data
{
    public function __construct(
        public readonly string $operationId,
        public readonly string $composerName,
        public readonly string $versionConstraint,
        public readonly ?string $repositoryUrl,
        public readonly string $label,
    ) {}
}
