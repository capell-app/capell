<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

final class FrontendResourceSelectionData extends Data
{
    /**
     * @param  list<FrontendResourceContributionData>  $contributions
     * @param  list<FrontendResourceHintData>  $hints
     */
    public function __construct(
        public readonly array $contributions,
        public readonly array $hints = [],
    ) {}
}
