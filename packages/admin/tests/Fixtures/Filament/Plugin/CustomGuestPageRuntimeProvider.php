<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Override;

final class CustomGuestPageRuntimeProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->tag([CustomGuestPagePanelExtender::class], AdminPanelExtender::TAG);
    }

    public function boot(): void
    {
        Log::spy();
    }
}
