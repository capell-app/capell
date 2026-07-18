<?php

declare(strict_types=1);

namespace Capell\Core\Support\Assets;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Closure;
use ReflectionFunction;

/** @extends AbstractKeyedRegistry<Closure> */
final class VendorAssetConditionRegistry extends AbstractKeyedRegistry
{
    public function register(string $name, callable $condition): void
    {
        $name = trim($name);

        if ($name === '') {
            return;
        }

        $this->setItem($name, $condition(...));
    }

    public function passes(?string $condition, mixed ...$arguments): bool
    {
        if ($condition === null || $condition === '') {
            return true;
        }

        if (! $this->hasItem($condition)) {
            return false;
        }

        $callback = $this->getItem($condition);

        if (! $callback instanceof Closure) {
            return false;
        }

        $parameterCount = new ReflectionFunction($callback)->getNumberOfParameters();

        return $callback(...array_slice($arguments, 0, $parameterCount));
    }
}
