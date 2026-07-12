<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Contracts\AdminResourceResolver as AdminResourceResolverContract;

final class AdminResourceResolver implements AdminResourceResolverContract
{
    public function __construct(private readonly CapellAdminManager $manager) {}

    public function hasPageResource(string $name = 'default'): bool
    {
        return $name === 'default' || $this->manager->hasResource('Page', $name);
    }

    public function getPageResource(string $name = 'default'): ?string
    {
        return $name === 'default'
            ? PageResource::class
            : $this->manager->getResource('Page', $name);
    }
}
