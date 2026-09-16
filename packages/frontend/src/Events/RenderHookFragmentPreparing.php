<?php

declare(strict_types=1);

namespace Capell\Frontend\Events;

use Capell\Frontend\Data\RenderHookFragmentReferenceData;

final class RenderHookFragmentPreparing
{
    public function __construct(
        public RenderHookFragmentReferenceData $fragment,
    ) {}
}
