<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard\Fixtures;

use Capell\Admin\Filament\Pages\CapellDashboard;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Override;

class CompositionDashboard extends CapellDashboard
{
    /** @var list<class-string<Widget>|WidgetConfiguration> */
    public array $widgets = [];

    /** @return array<int, Action|ActionGroup> */
    public function headerActionsForTest(): array
    {
        return $this->getHeaderActions();
    }

    #[Override]
    public function getWidgets(): array
    {
        return $this->widgets;
    }
}
