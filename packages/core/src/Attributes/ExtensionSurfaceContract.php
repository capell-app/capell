<?php

declare(strict_types=1);

namespace Capell\Core\Attributes;

use Attribute;
use Capell\Core\Enums\Extensions\ExtensionSurfaceStability;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ExtensionSurfaceContract
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $ownerPackage,
        public ExtensionSurfaceStability $stability,
        public string $introducedVersion,
        public string $summary,
        public ?string $contractTestId = null,
    ) {}
}
