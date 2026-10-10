<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

class SingletonLifetimeParentFixture extends SingletonLifetimeGrandparentFixture
{
    protected array $parentCache = [];
}
