<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Schemas\Components\Concerns\CanBeCollapsed;
use Filament\Schemas\Components\Tabs;

class CollapsibleTabs extends Tabs
{
    use CanBeCollapsed;

    protected string $view = 'capell-admin::components.schemas.collapsible-tabs';
}
