<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Illuminate\Support\ServiceProvider;
use Override;

/** An installed package that adds pass-through authentication middleware while the panel boots. */
final class BootAuthenticationRuntimeProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->tag([BootAuthenticationPanelExtender::class], AdminPanelExtender::TAG);
    }
}
