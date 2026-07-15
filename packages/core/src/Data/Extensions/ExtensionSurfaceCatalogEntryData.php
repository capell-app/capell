<?php

declare(strict_types=1);

namespace Capell\Core\Data\Extensions;

use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;
use Spatie\LaravelData\Data;

final class ExtensionSurfaceCatalogEntryData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $identifier,
        public readonly string $ownerPackage,
        public readonly ExtensionSurfaceStability $stability,
        public readonly string $introducedVersion,
        public readonly string $summary,
        public readonly ?string $contractTestId = null,
    ) {}
}
