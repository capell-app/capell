<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

class SingletonLifetimeGrandparentFixture
{
    private array $privateParentCache = [];

    public function privateParentCache(): array
    {
        return $this->privateParentCache;
    }
}
