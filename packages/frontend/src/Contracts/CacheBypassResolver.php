<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

interface CacheBypassResolver
{
    public function shouldBypass(): bool;
}
