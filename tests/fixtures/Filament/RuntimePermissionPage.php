<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Filament;

use Filament\Pages\Page;

class RuntimePermissionPage extends Page
{
    protected string $view = 'capell-tests::runtime-permission-page';
}
