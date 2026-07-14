<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

interface FrontendOutputCacheInvalidator
{
    public function invalidateAll(): void;
}
