<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\ExtensionContributionType;
use Spatie\LaravelData\Data;

final class RenderableContributionIdentityData extends Data
{
    public function __construct(
        public readonly string $owner,
        public readonly ExtensionContributionType $type,
        public readonly ?string $class = null,
        public readonly bool $cacheSafe = true,
    ) {}
}
