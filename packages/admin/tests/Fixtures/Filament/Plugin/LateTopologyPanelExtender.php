<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Filament\Panel;
use Override;

/** Mirrors an extender that registers a Filament plugin contributing its own resource. */
final class LateTopologyPanelExtender implements AdminPanelExtender
{
    #[Override]
    public function extend(Panel $panel): void
    {
        if (! in_array(LateRuntimeResource::class, $panel->getResources(), true)) {
            $panel->resources([LateRuntimeResource::class]);
        }
    }
}
