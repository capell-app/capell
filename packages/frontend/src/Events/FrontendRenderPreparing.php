<?php

declare(strict_types=1);

namespace Capell\Frontend\Events;

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderContextData;

final class FrontendRenderPreparing
{
    public function __construct(
        public FrontendContextReader $context,
        public FrontendRenderContextData $renderContext,
    ) {}
}
