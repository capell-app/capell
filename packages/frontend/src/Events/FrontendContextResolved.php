<?php

declare(strict_types=1);

namespace Capell\Frontend\Events;

use Capell\Frontend\Data\FrontendContext as FrontendContextDto;

final class FrontendContextResolved
{
    public function __construct(public FrontendContextDto $context) {}
}
