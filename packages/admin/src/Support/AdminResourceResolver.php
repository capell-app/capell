<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Contracts\AdminResourceResolver as AdminResourceResolverContract;

final class AdminResourceResolver implements AdminResourceResolverContract
{
    public function hasPageResource(string $name = 'default'): bool
    {
        return $this->manager()->hasResource('Page', $name);
    }

    public function getPageResource(string $name = 'default'): ?string
    {
        return $this->manager()->getResource('Page', $name);
    }

    /**
     * Resolve the manager through the CapellAdmin facade so reads always hit the
     * same instance that admin-surface contributions are registered against.
     * Injecting the manager can capture a different instance than the facade
     * caches, leaving this resolver reading an empty surface registry.
     */
    private function manager(): CapellAdminManager
    {
        return CapellAdmin::getFacadeRoot();
    }
}
