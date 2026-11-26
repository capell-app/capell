<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

/**
 * A host-owned area of signed-in pages (an account area, a portal) that renders
 * inside the active theme through a shell page of the named system page type.
 *
 * @experimental
 */
final class ApplicationAreaData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $pageType,
        public readonly ?string $label = null,
    ) {}
}
