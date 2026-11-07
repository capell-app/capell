<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Filament\Panel;
use Override;

/** Adds authentication middleware during panel construction, as an installed security package does. */
final class BootAuthenticationPanelExtender implements AdminPanelExtender
{
    #[Override]
    public function extend(Panel $panel): void
    {
        $panel->authMiddleware([BootAuthenticationMiddleware::class], isPersistent: true);
    }
}
