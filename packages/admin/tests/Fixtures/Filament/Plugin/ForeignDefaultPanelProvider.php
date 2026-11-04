<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

/** A host's own default Filament panel, which Capell does not extend. */
final class ForeignDefaultPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->id('portal')->path('portal')->default();
    }
}
