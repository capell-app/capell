<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Filament\Panel;
use Override;
use RuntimeException;

final class FailingBootstrapPanelExtender implements AdminPanelExtender
{
    #[Override]
    public function extend(Panel $panel): void
    {
        throw new RuntimeException('Cold bootstrap panel failure.');
    }
}
