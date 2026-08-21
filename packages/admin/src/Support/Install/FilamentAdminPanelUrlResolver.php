<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Install;

use Capell\Core\Contracts\AdminPanelUrlResolver;
use Filament\Facades\Filament;
use Throwable;

final class FilamentAdminPanelUrlResolver implements AdminPanelUrlResolver
{
    public function resolve(?string $panelId = null): ?string
    {
        try {
            return Filament::getPanel($panelId ?? (string) config('capell-admin.panel.id', 'admin'))->getUrl();
        } catch (Throwable) {
            return null;
        }
    }
}
