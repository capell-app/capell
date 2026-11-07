<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

/** A host's own Filament panel beside the Capell panels, which Capell does not extend. */
final class ForeignPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->id('portal')->path('portal');
    }
}
