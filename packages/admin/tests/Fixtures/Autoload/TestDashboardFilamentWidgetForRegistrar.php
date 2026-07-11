<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Filament\Widgets\Widget;

final class TestDashboardFilamentWidgetForRegistrar extends Widget
{
    /** @var view-string */
    protected string $view = 'capell-admin::widgets.stub';
}
