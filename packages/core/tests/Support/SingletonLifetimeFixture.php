<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

final class SingletonLifetimeFixture extends SingletonLifetimeParentFixture
{
    use SingletonLifetimeTraitFixture;

    private string $operation = '';

    public function __construct(private readonly SingletonLifetimeMutableDependencyFixture $dependency) {}
}
