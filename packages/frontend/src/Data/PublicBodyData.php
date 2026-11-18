<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

/** The public document body attributes, resolved from the layout and theme records. */
final class PublicBodyData extends Data implements PublicRenderData
{
    public function __construct(
        public readonly string $bodyClass,
    ) {}
}
