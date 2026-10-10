<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

final class SingletonLifetimeMutableDependencyFixture
{
    private array $values = [];

    public function values(): array
    {
        return $this->values;
    }
}
