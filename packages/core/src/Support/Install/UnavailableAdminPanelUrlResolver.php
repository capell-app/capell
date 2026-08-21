<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Contracts\AdminPanelUrlResolver;

final class UnavailableAdminPanelUrlResolver implements AdminPanelUrlResolver
{
    public function resolve(?string $panelId = null): ?string
    {
        return null;
    }
}
