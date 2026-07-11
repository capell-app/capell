<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Filament\Pages\Page;

class TestLatePage extends Page
{
    protected static ?string $slug = 'test-late-page';

    protected string $view = 'capell-admin::components.header.lockdown-banner';
}
