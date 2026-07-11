<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Filament\Panel;

final class TestPanelExtenderForRegistrar implements AdminPanelExtender
{
    public function extend(Panel $panel): void
    {
        //
    }
}
