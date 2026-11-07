<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Filament\Panel;
use Override;

final class OldCacheDenyExtender implements AdminPanelExtender
{
    #[Override]
    public function extend(Panel $panel): void
    {
        $panel->middleware([PackageSecurityDenyMiddleware::class]);
    }
}
