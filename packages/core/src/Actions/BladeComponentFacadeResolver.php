<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\BladeComponentResolverInterface;
use Illuminate\Support\Facades\Blade;

class BladeComponentFacadeResolver implements BladeComponentResolverInterface
{
    public function getClassComponentAliases(): array
    {
        return Blade::getClassComponentAliases();
    }

    public function getClassComponentNamespaces(): array
    {
        return Blade::getClassComponentNamespaces();
    }
}
