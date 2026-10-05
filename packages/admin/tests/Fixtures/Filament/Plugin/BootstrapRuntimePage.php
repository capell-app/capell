<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Filament\Pages\Page;

final class BootstrapRuntimePage extends Page
{
    protected string $view = 'filament-panels::pages.page';
}
