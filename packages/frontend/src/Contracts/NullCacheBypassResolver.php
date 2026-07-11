<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

final class NullCacheBypassResolver implements CacheBypassResolver
{
    public function shouldBypass(): bool
    {
        return false;
    }
}
