<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Frontend\Data\Assets\FrontendPackageDependencyData;

final class FrontendPackageDependencyRegistry
{
    /** @var array<int, FrontendPackageDependencyData> */
    private array $dependencies = [];

    public function register(FrontendPackageDependencyData $dependency): void
    {
        $this->dependencies[] = $dependency;
    }

    /** @return array<int, FrontendPackageDependencyData> */
    public function all(): array
    {
        return $this->dependencies;
    }
}
