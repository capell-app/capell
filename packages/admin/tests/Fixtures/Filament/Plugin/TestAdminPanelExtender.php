<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Filament\Panel;

final class TestAdminPanelExtender implements AdminPanelExtender
{
    public static bool $called = false;

    public function extend(Panel $panel): void
    {
        self::$called = true;
    }
}
