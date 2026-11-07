<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Filament\Panel;
use Override;

final class PackageSecurityDenyPanelExtender implements AdminPanelExtender
{
    #[Override]
    public function extend(Panel $panel): void
    {
        $panel->authMiddleware([PackageSecurityDenyMiddleware::class], isPersistent: true);
    }
}
