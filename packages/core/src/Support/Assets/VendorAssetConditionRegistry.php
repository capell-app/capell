<?php

declare(strict_types=1);

namespace Capell\Core\Support\Assets;

use Closure;
use ReflectionFunction;

final class VendorAssetConditionRegistry
{
    /** @var array<string, Closure> */
    private array $conditions = [];

    public function register(string $name, callable $condition): void
    {
        $name = trim($name);

        if ($name === '') {
            return;
        }

        $this->conditions[$name] = $condition(...);
    }

    public function passes(?string $condition, mixed ...$arguments): bool
    {
        if ($condition === null || $condition === '') {
            return true;
        }

        if (! isset($this->conditions[$condition])) {
            return false;
        }

        $callback = $this->conditions[$condition];
        $parameterCount = new ReflectionFunction($callback)->getNumberOfParameters();

        return $callback(...array_slice($arguments, 0, $parameterCount));
    }
}
