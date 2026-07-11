<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Panel;

interface AdminPanelExtender
{
    public const string TAG = 'capell.admin.panel_extenders';

    public function extend(Panel $panel): void;
}
